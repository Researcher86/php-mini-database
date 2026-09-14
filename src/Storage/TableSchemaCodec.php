<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PhpMiniDatabase\Schema\Constraint\Constraint;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\TypeFactory;

/**
 * Translates a Table to and from the JSON shape PLAN.md §6.3 describes for
 * `schema.json` — the one on-disk format for a table's shape, read by
 * `Catalog` and, eventually, written by `CREATE TABLE` and `ALTER TABLE`.
 *
 * Column types round-trip through their *name* ("VARCHAR(255)"), via
 * `TypeFactory`, so the file reads as the DDL that produced it. A default
 * value is stored exactly as `Column::rawDefault()` returns it — whatever
 * JSON-native shape it was given in, not the type's canonical form — which
 * is what keeps a DATE default a plain string in the file instead of
 * demanding a format `Type` has no way to produce.
 */
final readonly class TableSchemaCodec
{
    public function __construct(
        private TypeFactory $types = new TypeFactory(),
    ) {
    }

    /** @return array<string, mixed> */
    public function encode(Table $table): array
    {
        return [
            'name' => $table->name,
            'columns' => array_map($this->encodeColumn(...), $table->columns()),
            'constraints' => array_map($this->encodeConstraint(...), $table->constraints()),
            'indexes' => array_map($this->encodeIndex(...), $table->indexes()),
        ];
    }

    /** @param array<string, mixed> $data */
    public function decode(array $data): Table
    {
        $name = $this->string($data, 'name');

        return new Table(
            $name,
            array_map($this->decodeColumn(...), $this->list($data, 'columns')),
            array_map(
                fn (array $constraint): Constraint => $this->decodeConstraint($name, $constraint),
                $this->list($data, 'constraints'),
            ),
            array_map($this->decodeIndex(...), $this->list($data, 'indexes')),
        );
    }

    /** @return array<string, mixed> */
    private function encodeColumn(Column $column): array
    {
        $encoded = [
            'name' => $column->name,
            'type' => $column->type->name(),
            'not_null' => $column->notNull,
        ];

        if ($column->hasDefault()) {
            $encoded['default'] = $column->rawDefault();
        }

        return $encoded;
    }

    /** @param array<string, mixed> $data */
    private function decodeColumn(array $data): Column
    {
        $column = new Column(
            $this->string($data, 'name'),
            $this->types->fromName($this->string($data, 'type')),
            (bool) ($data['not_null'] ?? false),
        );

        return array_key_exists('default', $data) ? $column->withDefault($data['default']) : $column;
    }

    /** @return array<string, mixed> */
    private function encodeConstraint(Constraint $constraint): array
    {
        return match (true) {
            $constraint instanceof PrimaryKey => [
                'type' => 'PRIMARY_KEY',
                'columns' => $constraint->columns(),
            ],
            $constraint instanceof UniqueConstraint => [
                'type' => 'UNIQUE',
                'name' => $constraint->name(),
                'columns' => $constraint->columns(),
            ],
            $constraint instanceof ForeignKey => [
                'type' => 'FOREIGN_KEY',
                'name' => $constraint->name(),
                'columns' => $constraint->columns(),
                'referenced_table' => $constraint->referencedTable,
                'referenced_columns' => $constraint->referencedColumns(),
                'on_delete' => $constraint->onDelete->value,
                'on_update' => $constraint->onUpdate->value,
            ],
            $constraint instanceof CheckConstraint => [
                'type' => 'CHECK',
                'name' => $constraint->name(),
                'expression' => $constraint->expression,
                'columns' => $constraint->columns(),
            ],
            default => throw new SchemaException(sprintf('Cannot encode constraint of type %s.', $constraint::class)),
        };
    }

    /** @param array<string, mixed> $data */
    private function decodeConstraint(string $table, array $data): Constraint
    {
        $type = $this->string($data, 'type');

        return match ($type) {
            'PRIMARY_KEY' => new PrimaryKey($this->stringList($data, 'columns')),
            'UNIQUE' => new UniqueConstraint($this->string($data, 'name'), $this->stringList($data, 'columns')),
            'FOREIGN_KEY' => new ForeignKey(
                $this->string($data, 'name'),
                $this->stringList($data, 'columns'),
                $this->string($data, 'referenced_table'),
                $this->stringList($data, 'referenced_columns'),
                ReferentialAction::from($this->string($data, 'on_delete')),
                ReferentialAction::from($this->string($data, 'on_update')),
            ),
            'CHECK' => new CheckConstraint(
                $this->string($data, 'name'),
                $this->string($data, 'expression'),
                $this->stringList($data, 'columns'),
            ),
            default => throw new SchemaException(sprintf('Table "%s" has an unknown constraint type "%s".', $table, $type)),
        };
    }

    /** @return array<string, mixed> */
    private function encodeIndex(IndexDefinition $index): array
    {
        return [
            'name' => $index->name,
            'columns' => $index->columns(),
            'unique' => $index->unique,
        ];
    }

    /** @param array<string, mixed> $data */
    private function decodeIndex(array $data): IndexDefinition
    {
        return new IndexDefinition(
            $this->string($data, 'name'),
            $this->stringList($data, 'columns'),
            (bool) ($data['unique'] ?? false),
        );
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new SchemaException(sprintf('Expected schema field "%s" to be a string.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function list(array $data, string $key): array
    {
        if (!isset($data[$key])) {
            return [];
        }

        if (!is_array($data[$key])) {
            throw new SchemaException(sprintf('Expected schema field "%s" to be a list.', $key));
        }

        return array_values($data[$key]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function stringList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new SchemaException(sprintf('Expected schema field "%s" to be a list of strings.', $key));
        }

        foreach ($data[$key] as $value) {
            if (!is_string($value)) {
                throw new SchemaException(sprintf('Expected schema field "%s" to be a list of strings.', $key));
            }
        }

        return array_values($data[$key]);
    }
}

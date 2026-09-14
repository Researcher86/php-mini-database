<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Infrastructure\Path;
use PhpMiniDatabase\Schema\Constraint\Constraint;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Type\Type;
use PhpMiniDatabase\Storage\RecordSerializer;

/**
 * A table's shape: its columns, the constraints and indexes declared over
 * them, validated for internal consistency at construction — a `Table` that
 * exists is one a caller never has to re-check.
 *
 * "Internal" is the operative word: everything checked here is decidable
 * from the table alone (no duplicate or unknown columns, at most one
 * PRIMARY KEY, its columns all NOT NULL, every constraint and index naming
 * columns that exist). A foreign key's *other* table is not checked here —
 * `Storage\Catalog` does that, because only it has every table loaded at
 * once, and because a table that references itself has to be constructible
 * before it is known to exist.
 *
 * `Table` also owns the translation between a `Row` (values by column name)
 * and the positional bytes `RecordSerializer` deals in — `valuesFromRow()`
 * fills in defaults and enforces NOT NULL, the one constraint that needs
 * nothing beyond the row itself. UNIQUE, FOREIGN KEY and CHECK all need
 * state a `Table` does not have (an index, another table, an expression
 * evaluator) and are enforced by the executor instead (Phase 10).
 */
final readonly class Table
{
    /** @var array<string, Column> */
    private array $columnsByName;

    /**
     * @param list<Column>           $columns
     * @param list<Constraint>       $constraints
     * @param list<IndexDefinition>  $indexes
     */
    public function __construct(
        public string $name,
        private array $columns,
        private array $constraints = [],
        private array $indexes = [],
        private RecordSerializer $serializer = new RecordSerializer(),
    ) {
        Path::identifier($name);

        if ($columns === []) {
            throw new SchemaException(sprintf('Table "%s" must have at least one column.', $name));
        }

        $byName = [];
        foreach ($columns as $column) {
            if (isset($byName[$column->name])) {
                throw new SchemaException(sprintf('Table "%s" declares column "%s" twice.', $name, $column->name));
            }

            $byName[$column->name] = $column;
        }
        $this->columnsByName = $byName;

        $this->validateConstraints($name);
        $this->validateIndexes($name);
    }

    /** @return list<Column> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columnsByName[$name]);
    }

    public function column(string $name): Column
    {
        return $this->columnsByName[$name]
            ?? throw new SchemaException(sprintf('Table "%s" has no column "%s".', $this->name, $name));
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (Column $column): string => $column->name, $this->columns);
    }

    /** @return list<Type> */
    public function types(): array
    {
        return array_map(static fn (Column $column): Type => $column->type, $this->columns);
    }

    /** @return list<Constraint> */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /** @return list<IndexDefinition> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    public function primaryKey(): ?PrimaryKey
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint instanceof PrimaryKey) {
                return $constraint;
            }
        }

        return null;
    }

    public function hasIndex(string $name): bool
    {
        foreach ($this->indexes as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }

    /** A copy of this table with one more index declared. */
    public function withIndex(IndexDefinition $index): self
    {
        return new self($this->name, $this->columns, $this->constraints, [...$this->indexes, $index], $this->serializer);
    }

    /** A copy of this table with the named index no longer declared. */
    public function withoutIndex(string $name): self
    {
        if (!$this->hasIndex($name)) {
            throw new SchemaException(sprintf('Table "%s" has no index "%s".', $this->name, $name));
        }

        $remaining = array_values(array_filter($this->indexes, static fn (IndexDefinition $i): bool => $i->name !== $name));

        return new self($this->name, $this->columns, $this->constraints, $remaining, $this->serializer);
    }

    /**
     * Resolve a Row into the positional values RecordSerializer expects: one
     * per column, in column order, missing values filled from defaults.
     *
     * @throws SchemaException              when the row names a column this
     *                                       table does not have
     * @throws ConstraintViolationException when a NOT NULL column ends up
     *                                       without a value
     *
     * @return list<mixed>
     */
    public function valuesFromRow(Row $row): array
    {
        foreach ($row->columnNames() as $name) {
            if (!$this->hasColumn($name)) {
                throw new SchemaException(sprintf('Table "%s" has no column "%s".', $this->name, $name));
            }
        }

        $values = [];

        foreach ($this->columns as $column) {
            $value = match (true) {
                $row->has($column->name) => $column->type->cast($row->get($column->name)),
                $column->hasDefault() => $column->defaultValue(),
                default => null,
            };

            if ($value === null && $column->notNull) {
                throw new ConstraintViolationException(sprintf(
                    'Column "%s.%s" does not allow NULL.',
                    $this->name,
                    $column->name,
                ));
            }

            $values[] = $value;
        }

        return $values;
    }

    /** @param list<mixed> $values */
    public function rowFromValues(array $values): Row
    {
        return new Row(array_combine($this->columnNames(), $values));
    }

    public function serializeRow(Row $row): string
    {
        return $this->serializer->serialize($this->valuesFromRow($row), $this->types());
    }

    public function deserializeRow(string $record): Row
    {
        return $this->rowFromValues($this->serializer->deserialize($record, $this->types()));
    }

    private function validateConstraints(string $name): void
    {
        $primaryKeys = 0;
        $seenNames = [];

        foreach ($this->constraints as $constraint) {
            if (isset($seenNames[$constraint->name()])) {
                throw new SchemaException(sprintf('Table "%s" declares constraint "%s" twice.', $name, $constraint->name()));
            }
            $seenNames[$constraint->name()] = true;

            $this->assertColumnsExist($name, $constraint->name(), $constraint->columns());

            if ($constraint instanceof PrimaryKey) {
                $primaryKeys++;

                foreach ($constraint->columns() as $column) {
                    if (!$this->columnsByName[$column]->notNull) {
                        throw new SchemaException(sprintf(
                            'Primary key column "%s.%s" must be declared NOT NULL.',
                            $name,
                            $column,
                        ));
                    }
                }
            }
        }

        if ($primaryKeys > 1) {
            throw new SchemaException(sprintf('Table "%s" declares more than one PRIMARY KEY.', $name));
        }
    }

    private function validateIndexes(string $name): void
    {
        $seenNames = [];

        foreach ($this->indexes as $index) {
            if (isset($seenNames[$index->name])) {
                throw new SchemaException(sprintf('Table "%s" declares index "%s" twice.', $name, $index->name));
            }
            $seenNames[$index->name] = true;

            $this->assertColumnsExist($name, $index->name, $index->columns());
        }
    }

    /** @param list<string> $columns */
    private function assertColumnsExist(string $table, string $subject, array $columns): void
    {
        foreach ($columns as $column) {
            if (!$this->hasColumn($column)) {
                throw new SchemaException(sprintf('"%s" on table "%s" names unknown column "%s".', $subject, $table, $column));
            }
        }
    }
}

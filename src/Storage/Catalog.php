<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Infrastructure\AtomicWriter;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Infrastructure\Path;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\Table;

/**
 * Every table's shape, kept on disk as one `schema.json` per table under
 * `<data>/tables/<name>/`.
 *
 * There is no separate index file listing table names: the `tables/`
 * directory listing *is* the catalog. A second file naming the same tables
 * would be one more thing to keep atomically in step with the directory —
 * created, dropped, renamed together — for no question it answers that
 * `scandir()` does not already answer directly.
 *
 * What the catalog validates that a lone `Table` cannot: a foreign key's
 * referenced table and columns actually exist, and that they are covered by
 * a PRIMARY KEY or UNIQUE constraint (otherwise "the row this points at" is
 * not a single row). A self-referencing foreign key — a table pointing at
 * itself — is checked against the table being created, since it cannot
 * exist in the catalog yet at that point.
 *
 * Dropping a table is refused while another table still has a foreign key
 * pointing at it, the same way `DROP TABLE` is refused on a running SQL
 * database absent a `CASCADE` this project does not implement.
 */
final readonly class Catalog
{
    private string $tablesDirectory;

    public function __construct(
        private string $dataDirectory,
        private FileSystem $files = new FileSystem(),
        private AtomicWriter $writer = new AtomicWriter(),
        private TableSchemaCodec $codec = new TableSchemaCodec(),
    ) {
        $this->tablesDirectory = Path::join($dataDirectory, 'tables');
    }

    public static function open(string $dataDirectory): self
    {
        $catalog = new self($dataDirectory);

        $catalog->files->ensureDirectory($dataDirectory);
        $catalog->files->ensureDirectory($catalog->tablesDirectory);

        return $catalog;
    }

    public function dataDirectory(): string
    {
        return $this->dataDirectory;
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        $names = array_filter(
            $this->files->listDirectory($this->tablesDirectory),
            fn (string $name): bool => $this->files->isDirectory(Path::join($this->tablesDirectory, $name)),
        );
        sort($names);

        return $names;
    }

    public function hasTable(string $name): bool
    {
        return $this->files->exists($this->schemaPath($name));
    }

    public function table(string $name): Table
    {
        if (!$this->hasTable($name)) {
            throw new SchemaException(sprintf('Table "%s" does not exist.', $name));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($this->files->read($this->schemaPath($name)), true, flags: JSON_THROW_ON_ERROR);

        return $this->codec->decode($data);
    }

    public function createTable(Table $table): void
    {
        if ($this->hasTable($table->name)) {
            throw new SchemaException(sprintf('Table "%s" already exists.', $table->name));
        }

        $this->files->ensureDirectory(Path::join($this->tablesDirectory, $table->name));
        $this->save($table);
    }

    /**
     * Overwrites an existing table's schema.json — how `CREATE INDEX` and
     * `DROP INDEX` persist the index they added or removed, via
     * `Table::withIndex()`/`withoutIndex()`. The table must already exist;
     * this never creates or drops its directory.
     */
    public function updateTable(Table $table): void
    {
        if (!$this->hasTable($table->name)) {
            throw new SchemaException(sprintf('Table "%s" does not exist.', $table->name));
        }

        $this->save($table);
    }

    private function save(Table $table): void
    {
        $this->validateForeignKeys($table);

        $this->writer->write($this->schemaPath($table->name), json_encode($this->codec->encode($table), JSON_THROW_ON_ERROR));
    }

    public function dropTable(string $name): void
    {
        if (!$this->hasTable($name)) {
            throw new SchemaException(sprintf('Table "%s" does not exist.', $name));
        }

        foreach ($this->tableNames() as $other) {
            if ($other === $name) {
                continue;
            }

            foreach ($this->table($other)->constraints() as $constraint) {
                if ($constraint instanceof ForeignKey && $constraint->referencedTable === $name) {
                    throw new SchemaException(sprintf(
                        'Cannot drop table "%s": "%s.%s" still references it.',
                        $name,
                        $other,
                        $constraint->name(),
                    ));
                }
            }
        }

        $this->files->removeDirectory(Path::join($this->tablesDirectory, $name));
    }

    private function validateForeignKeys(Table $table): void
    {
        foreach ($table->constraints() as $constraint) {
            if (!$constraint instanceof ForeignKey) {
                continue;
            }

            $referenced = $constraint->referencedTable === $table->name ? $table : $this->referencedTable($table, $constraint);

            foreach ($constraint->referencedColumns() as $column) {
                if (!$referenced->hasColumn($column)) {
                    throw new SchemaException(sprintf(
                        '"%s" on table "%s" references unknown column "%s.%s".',
                        $constraint->name(),
                        $table->name,
                        $referenced->name,
                        $column,
                    ));
                }
            }

            if (!$this->isCoveredByAUniqueConstraint($referenced, $constraint->referencedColumns())) {
                throw new SchemaException(sprintf(
                    '"%s" on table "%s" references "%s" (%s), which is not a PRIMARY KEY or UNIQUE constraint.',
                    $constraint->name(),
                    $table->name,
                    $referenced->name,
                    implode(', ', $constraint->referencedColumns()),
                ));
            }
        }
    }

    private function referencedTable(Table $table, ForeignKey $constraint): Table
    {
        if (!$this->hasTable($constraint->referencedTable)) {
            throw new SchemaException(sprintf(
                '"%s" on table "%s" references unknown table "%s".',
                $constraint->name(),
                $table->name,
                $constraint->referencedTable,
            ));
        }

        return $this->table($constraint->referencedTable);
    }

    /** @param list<string> $columns */
    private function isCoveredByAUniqueConstraint(Table $table, array $columns): bool
    {
        $wanted = $columns;
        sort($wanted);

        foreach ($table->constraints() as $constraint) {
            if (!$constraint instanceof PrimaryKey && !$constraint instanceof UniqueConstraint) {
                continue;
            }

            $declared = $constraint->columns();
            sort($declared);

            if ($declared === $wanted) {
                return true;
            }
        }

        return false;
    }

    private function schemaPath(string $name): string
    {
        return Path::join($this->tablesDirectory, $name, 'schema.json');
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Infrastructure\Path;
use PhpMiniDatabase\Storage\BTreeIndex;
use PhpMiniDatabase\Storage\Catalog;
use PhpMiniDatabase\Storage\HeapFile;

/**
 * The database as a caller sees it: a directory on disk, the tables in it,
 * and — as of Phase 5 — their data. `execute()` and `query()` from the
 * public API (PLAN.md §8.1) need the SQL parser and executor that now
 * exist; they land here as this class's callers need them, without
 * changing what is already here.
 *
 * No longer immutable: `heapFile()` opens each table's `heap.dat` once and
 * keeps it open, the same way a real connection keeps its file handles
 * rather than reopening them per statement; `index()` does the same for a
 * table's `.idx` files (Phase 6). `close()` releases them all.
 */
final class Database
{
    /** @var array<string, HeapFile> */
    private array $heapFiles = [];

    /** @var array<string, BTreeIndex> */
    private array $indexes = [];

    private function __construct(
        private readonly Catalog $catalog,
        private readonly FileSystem $files = new FileSystem(),
    ) {
    }

    public static function open(string $dataDirectory): self
    {
        return new self(Catalog::open($dataDirectory));
    }

    public function createTable(Table $table): void
    {
        $this->catalog->createTable($table);
    }

    public function dropTable(string $name): void
    {
        $this->catalog->dropTable($name);
    }

    public function hasTable(string $name): bool
    {
        return $this->catalog->hasTable($name);
    }

    public function table(string $name): Table
    {
        return $this->catalog->table($name);
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return $this->catalog->tableNames();
    }

    /**
     * Adds an index to an existing table's schema — `CREATE INDEX`'s
     * persistence step. The table is re-validated the same way
     * `createTable()` validates a new one.
     */
    public function addIndex(string $table, IndexDefinition $index): void
    {
        $this->catalog->updateTable($this->table($table)->withIndex($index));
    }

    /**
     * `DROP INDEX`'s persistence step, and the inverse of addIndex(). The
     * `.idx` file itself is deleted, not just uncached — leaving it behind
     * would mean a later `CREATE INDEX` reusing the same name reopens a
     * file `BTreeIndex` still sees as already initialized, and silently
     * serves whatever stale tree was in it.
     */
    public function dropIndex(string $table, string $indexName): void
    {
        $this->catalog->updateTable($this->table($table)->withoutIndex($indexName));

        $cacheKey = $this->indexCacheKey($table, $indexName);

        if (isset($this->indexes[$cacheKey])) {
            $this->indexes[$cacheKey]->close();
            unset($this->indexes[$cacheKey]);
        }

        $this->files->delete($this->indexPath($table, $indexName));
    }

    /**
     * The table's data file, opened on first use and reused after —
     * `Catalog::createTable()` never touches it, so nothing else creates it
     * ahead of time.
     */
    public function heapFile(string $name): HeapFile
    {
        if (!isset($this->heapFiles[$name])) {
            // table() throws first if $name isn't real, so a heap file is
            // never opened for a table that does not exist.
            $this->table($name);

            $path = Path::join($this->catalog->dataDirectory(), 'tables', $name, 'heap.dat');
            $this->heapFiles[$name] = HeapFile::open($path);
        }

        return $this->heapFiles[$name];
    }

    /**
     * One of a table's indexes, opened on first use and reused after. The
     * index must already be declared on the table (`withIndex()`) —
     * nothing here creates the declaration, only the open file handle onto
     * whatever it names.
     */
    public function index(string $table, string $indexName): BTreeIndex
    {
        $cacheKey = $this->indexCacheKey($table, $indexName);

        if (!isset($this->indexes[$cacheKey])) {
            $definition = $this->indexDefinition($table, $indexName);
            $columnType = $this->table($table)->column($definition->columns()[0])->type;

            $this->indexes[$cacheKey] = BTreeIndex::open($this->indexPath($table, $indexName), $columnType, $definition->unique);
        }

        return $this->indexes[$cacheKey];
    }

    public function close(): void
    {
        foreach ($this->heapFiles as $heap) {
            $heap->close();
        }
        $this->heapFiles = [];

        foreach ($this->indexes as $index) {
            $index->close();
        }
        $this->indexes = [];
    }

    private function indexDefinition(string $table, string $indexName): IndexDefinition
    {
        foreach ($this->table($table)->indexes() as $index) {
            if ($index->name === $indexName) {
                return $index;
            }
        }

        throw new SchemaException(sprintf('Table "%s" has no index "%s".', $table, $indexName));
    }

    private function indexPath(string $table, string $indexName): string
    {
        return Path::join($this->catalog->dataDirectory(), 'tables', $table, $indexName . '.idx');
    }

    private function indexCacheKey(string $table, string $indexName): string
    {
        return $table . '.' . $indexName;
    }
}

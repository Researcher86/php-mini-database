<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Infrastructure\Path;
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
 * rather than reopening them per statement. `close()` releases them.
 */
final class Database
{
    /** @var array<string, HeapFile> */
    private array $heapFiles = [];

    private function __construct(
        private readonly Catalog $catalog,
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

    public function close(): void
    {
        foreach ($this->heapFiles as $heap) {
            $heap->close();
        }

        $this->heapFiles = [];
    }
}

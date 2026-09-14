<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Storage\Catalog;

/**
 * The database as a caller sees it: a directory on disk and the tables in
 * it. Today this is `Catalog` with a friendlier name — `execute()` and
 * `query()` from the public API (PLAN.md §8.1) need a SQL parser and an
 * executor that do not exist yet (Phases 4 and 5), and land here once they
 * do, without this class's callers needing to change.
 */
final readonly class Database
{
    private function __construct(
        private Catalog $catalog,
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
}

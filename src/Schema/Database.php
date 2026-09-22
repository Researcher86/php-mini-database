<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Infrastructure\Path;
use PhpMiniDatabase\Storage\BTreeIndex;
use PhpMiniDatabase\Storage\Catalog;
use PhpMiniDatabase\Storage\HeapFile;
use PhpMiniDatabase\Transaction\LockManager;
use PhpMiniDatabase\Transaction\TransactionManager;
use PhpMiniDatabase\Transaction\Wal;

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
 * table's `.idx` files (Phase 6), and `wal()` for the write-ahead log
 * (Phase 8). `close()` releases them all.
 *
 * `locks()` and `transactions()` (Phase 8) are owned here rather than by
 * `Execution\Executor` for the same reason: a "transaction" and a "lock" are
 * properties of a connection to this database, not of one particular
 * `Executor` object built to talk to it — two `Executor`s wrapping the same
 * `Database` need to see the same active transaction and contend for the
 * same locks, the way two statements sent down one real database connection
 * would.
 */
final class Database
{
    /** @var array<string, HeapFile> */
    private array $heapFiles = [];

    /** @var array<string, BTreeIndex> */
    private array $indexes = [];

    private ?Wal $wal = null;

    private ?LockManager $locks = null;

    private ?TransactionManager $transactions = null;

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

    /** Where this database's files live — `Transaction\Wal`'s path is built from this. */
    public function dataDirectory(): string
    {
        return $this->catalog->dataDirectory();
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

    /**
     * The write-ahead log backing `Transaction\TransactionManager`, opened
     * on first use and reused after — one log per database, shared by
     * every `Execution\Executor` built against this instance.
     */
    public function wal(): Wal
    {
        if ($this->wal === null) {
            $directory = Path::join($this->catalog->dataDirectory(), 'wal');
            $this->files->ensureDirectory($directory);
            $this->wal = Wal::open(Path::join($directory, 'wal.log'), $this->files);
        }

        return $this->wal;
    }

    /** The lock table every transaction against this database contends for. */
    public function locks(): LockManager
    {
        return $this->locks ??= new LockManager();
    }

    /**
     * The one `TransactionManager` for this database, shared by every
     * `Executor` built against it, so that `BEGIN`ning a transaction
     * through one is visible to (and blocks a second `BEGIN` from) another.
     */
    public function transactions(): TransactionManager
    {
        return $this->transactions ??= new TransactionManager($this->wal(), $this->locks());
    }

    /**
     * Pushes every currently-open heap file and index all the way to the
     * device — `Executor` wires this in as `TransactionManager`'s sync
     * handler, called at the end of every `COMMIT`/`ROLLBACK`, right before
     * the WAL is checkpointed. Without it, `PageManager::write()`'s plain
     * `fwrite()` leaves a mutated page only as durable as the OS's own page
     * cache decides to make it — the WAL record for that change can already
     * be gone (checkpointed) by the time the page itself actually reaches
     * disk. See DECISIONS.md.
     *
     * Syncs every open table and index unconditionally, not only the ones
     * this particular transaction touched: tracking a per-transaction dirty
     * set would need `HeapFile`/`BTreeIndex` to report which pages they
     * wrote, machinery this project's `PageManager` does not have (see "No
     * buffer pool yet" in DECISIONS.md) — an `fsync()` on a handful of
     * already-open files this engine's scale keeps open anyway is the
     * simpler, obviously-correct trade.
     */
    public function syncStorage(): void
    {
        foreach ($this->heapFiles as $heap) {
            $heap->sync();
        }

        foreach ($this->indexes as $index) {
            $index->sync();
        }
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

        $this->wal?->close();
        $this->wal = null;
        $this->transactions = null;
        $this->locks = null;
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

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PhpMiniDatabase\Transaction\LockMode;
use PHPUnit\Framework\TestCase;

/**
 * `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` driven end-to-end through
 * `Executor::run()` against a real on-disk `Database` — proving that the
 * WAL, `LockManager` and `TransactionManager` pieces (each already unit-
 * tested in isolation under Transaction/) actually cooperate the way a
 * caller sending SQL text would experience them.
 */
final class ExecutorTransactionTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;

    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
        $this->executor->run(
            'CREATE TABLE users (
                id INT PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            )',
        );
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql, ?Executor $executor = null): array
    {
        $result = ($executor ?? $this->executor)->run($sql);
        self::assertInstanceOf(QueryResult::class, $result);

        return array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false));
    }

    public function testACommittedInsertSurvives(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run('COMMIT');

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users'));
    }

    public function testRollbackUndoesAnInsert(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run('ROLLBACK');

        self::assertSame([], $this->query('SELECT * FROM users'));
    }

    public function testRollbackUndoesAnUpdate(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        $this->executor->run('BEGIN');
        $this->executor->run("UPDATE users SET name = 'Ann2' WHERE id = 1");
        $this->executor->run('ROLLBACK');

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users'));
    }

    public function testRollbackUndoesADelete(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        $this->executor->run('BEGIN');
        $this->executor->run('DELETE FROM users WHERE id = 1');
        $this->executor->run('ROLLBACK');

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users'));
    }

    public function testRollingBackToASavepointUndoesOnlyWhatCameAfterIt(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run('SAVEPOINT sp1');
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bob')");
        $this->executor->run('ROLLBACK TO SAVEPOINT sp1');
        $this->executor->run('COMMIT');

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users'));
    }

    public function testASavepointCanBeReusedAfterRollingBackToIt(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run('SAVEPOINT sp1');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run('ROLLBACK TO SAVEPOINT sp1');
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bob')");
        $this->executor->run('ROLLBACK TO SAVEPOINT sp1');
        $this->executor->run('COMMIT');

        self::assertSame([], $this->query('SELECT * FROM users'));
    }

    public function testAMultiRowInsertIsAtomic(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        try {
            // The third row's id collides with the one already committed
            // above - the whole statement, including the two rows that
            // would otherwise have succeeded, must leave nothing behind.
            $this->executor->run(
                "INSERT INTO users (id, name) VALUES (2, 'Bob'), (3, 'Cid'), (1, 'Dup')",
            );
            self::fail('Expected a ConstraintViolationException.');
        } catch (ConstraintViolationException) {
            // expected
        }

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users'));
    }

    public function testRecoveryUndoesATransactionThatNeverCommitted(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        $this->executor->run('BEGIN');
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bob')");
        // Simulate a crash: neither COMMIT nor ROLLBACK ever runs, and the
        // process (here, just this Executor and its in-memory lock/
        // transaction state) goes away without either.

        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $recovered = new Executor($this->database);

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users', $recovered));
    }

    /**
     * `TransactionManager` allows exactly one active transaction at a time
     * (see its own docblock) — and, since `Database` now owns the one
     * `TransactionManager` shared by every `Executor` built against it (not
     * each `Executor` its own), that limit holds *across* `Executor`
     * instances, not just within one: a second connection to the same
     * database sees the first one's transaction as open, the same way a
     * second real client connection would. Genuinely overlapping,
     * lock-contending transactions are not something this single-session
     * engine can express yet — that needs the multiple independent
     * sessions Phase 12's server introduces; `LockManager`'s own conflict
     * rules are covered directly in Transaction/LockManagerTest instead.
     */
    public function testASecondExecutorSeesTheFirstsOpenTransaction(): void
    {
        $this->executor->run('BEGIN');

        $second = new Executor($this->database);

        $this->expectException(TransactionException::class);
        $second->run('BEGIN');
    }

    public function testDdlIsNotTransactional(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run('CREATE TABLE posts (id INT PRIMARY KEY)');
        $this->executor->run('ROLLBACK');

        self::assertTrue($this->database->hasTable('posts'));
    }

    /**
     * `INSERT` takes an exclusive table lock precisely so a concurrent
     * `SERIALIZABLE` full scan's shared one (see `lockForRead()`) excludes
     * it — the phantom-prevention this pair of locks exists for. Standing
     * the shared holder up directly through `LockManager`'s own public API
     * is how this class simulates "another session already holds it",
     * since (as the test above explains) two genuinely overlapping
     * transactions cannot exist through `Executor::run()` alone yet.
     */
    public function testInsertIsBlockedByAConcurrentlyHeldSharedTableLock(): void
    {
        $this->database->locks()->acquireTableLock('users', 999, LockMode::SHARED);

        $this->expectException(TransactionException::class);
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
    }

    /**
     * A `SELECT` under `REPEATABLE_READ`/`SERIALIZABLE` holds a shared lock
     * on every row it reads until the transaction ends (`lockForRead()`),
     * so a conflicting exclusive lock from another transaction id must be
     * refused for as long as this transaction stays open.
     */
    public function testRepeatableReadHoldsASharedRowLockUntilTheTransactionEnds(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $id = $this->onlyRecordIdInUsers();

        $this->executor->run('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $this->query("SELECT * FROM users WHERE id = 1");

        $this->expectException(TransactionException::class);
        $this->database->locks()->acquireRowLock('users', $id, 999, LockMode::EXCLUSIVE);
    }

    /**
     * The counterpart to the test above: under the default `READ_COMMITTED`,
     * a `SELECT` holds no lock past the statement that ran it, so nothing
     * here is left for another transaction id to conflict with.
     */
    public function testReadCommittedHoldsNoLockPastTheStatement(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $id = $this->onlyRecordIdInUsers();

        $this->executor->run('BEGIN');
        $this->query('SELECT * FROM users WHERE id = 1');

        $this->database->locks()->acquireRowLock('users', $id, 999, LockMode::EXCLUSIVE);
        $this->addToAssertionCount(1);
    }

    private function onlyRecordIdInUsers(): RecordId
    {
        foreach ($this->database->heapFile('users')->scan() as $id => $record) {
            return $id;
        }

        self::fail('Expected "users" to hold at least one row.');
    }
}

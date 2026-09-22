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
use RuntimeException;

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

    /**
     * A `ROLLBACK` whose durability barrier fails leaves the transaction
     * open on purpose, for the caller to retry — so the retry has to
     * work. It would not if the first attempt's undo were applied a
     * second time: undoing an `INSERT` deletes the row, and deleting an
     * already-deleted row is a `StorageException`, which would leave the
     * transaction permanently unable to either commit or roll back.
     */
    public function testARollbackRetriedAfterAFailedSyncDoesNotUndoTheSameChangeTwice(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bob')");

        $this->database->transactions()->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        try {
            $this->executor->run('ROLLBACK');
            self::fail('Expected the sync failure to propagate.');
        } catch (RuntimeException) {
            // Expected - and the transaction stays open, by design.
        }

        self::assertTrue($this->executor->inTransaction());

        // The device is fine again; the caller retries, as the still-open
        // transaction invites them to.
        $this->database->transactions()->setSyncHandler(static function (): void {
        });

        $this->executor->run('ROLLBACK');

        self::assertFalse($this->executor->inTransaction());
        self::assertSame([], $this->query('SELECT * FROM users'));
    }

    /**
     * The window the retry test above cannot cover: the process does not
     * get to retry, it dies. The undo has been applied, the transaction's
     * own log is empty, and the WAL still shows an unfinished transaction
     * — so the next process's `recover()` undoes the same records a second
     * time, against rows that are already gone. That must leave the
     * database openable and the rollback intact, not throw: `recover()`
     * runs inside `Executor`'s constructor, so an exception there would
     * make every future `Executor` against this database fail too.
     */
    public function testACrashBetweenARollbacksUndoAndItsWalRecordStillRecoversCleanly(): void
    {
        $this->executor->run('BEGIN');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bob')");

        $this->database->transactions()->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        try {
            $this->executor->run('ROLLBACK');
            self::fail('Expected the sync failure to propagate.');
        } catch (RuntimeException) {
            // Expected. The undo is applied; the ROLLBACK record is not
            // written, because the barrier before it failed.
        }

        // Simulate the crash: the handles go away with the transaction
        // still unfinished as far as the WAL is concerned.
        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $recovered = new Executor($this->database);

        self::assertSame([], $this->query('SELECT * FROM users', $recovered));
    }

    /**
     * The same window, for a rolled-back `DELETE`. Restoring a deleted row
     * gives it a new `RecordId`, so unlike an undone `INSERT` the logged
     * id cannot say whether it is already back — a unique index is what
     * answers instead, and `users.id` is a `PRIMARY KEY`.
     */
    public function testACrashBetweenARollbacksUndoAndItsWalRecordDoesNotDuplicateARestoredRow(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        $this->executor->run('BEGIN');
        $this->executor->run('DELETE FROM users WHERE id = 1');

        $this->database->transactions()->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        try {
            $this->executor->run('ROLLBACK');
            self::fail('Expected the sync failure to propagate.');
        } catch (RuntimeException) {
            // Expected - the row is already restored at this point.
        }

        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $recovered = new Executor($this->database);

        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM users', $recovered));
    }

    /**
     * `UPDATE` then `DELETE` on the same row, rolled back. Undo runs in
     * reverse, so the `DELETE` is undone first — and if that put the row
     * back at a *new* `RecordId`, the `UPDATE` undo right after it would
     * address a slot that no longer holds anything and quietly do nothing,
     * leaving the updated value instead of the original. The first row
     * here exists only to free a lower slot inside the transaction, which
     * is what an ordinary `insert()` would have reused in preference to
     * the one the deleted row actually left.
     */
    public function testRollingBackAnUpdateFollowedByADeleteRestoresTheOriginalValue(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bea')");

        $this->executor->run('BEGIN');
        $this->executor->run('DELETE FROM users WHERE id = 1');
        $this->executor->run("UPDATE users SET name = 'Bob' WHERE id = 2");
        $this->executor->run('DELETE FROM users WHERE id = 2');
        $this->executor->run('ROLLBACK');

        self::assertSame(
            [['id' => 1, 'name' => 'Ann'], ['id' => 2, 'name' => 'Bea']],
            $this->query('SELECT * FROM users ORDER BY id'),
        );
    }

    /** The same shape, but the rollback is the one recovery performs after a crash. */
    public function testRecoveringAnUpdateFollowedByADeleteRestoresTheOriginalValue(): void
    {
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->executor->run("INSERT INTO users (id, name) VALUES (2, 'Bea')");

        $this->executor->run('BEGIN');
        $this->executor->run('DELETE FROM users WHERE id = 1');
        $this->executor->run("UPDATE users SET name = 'Bob' WHERE id = 2");
        $this->executor->run('DELETE FROM users WHERE id = 2');
        // Simulate a crash: neither COMMIT nor ROLLBACK ever runs.

        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $recovered = new Executor($this->database);

        self::assertSame(
            [['id' => 1, 'name' => 'Ann'], ['id' => 2, 'name' => 'Bea']],
            $this->query('SELECT * FROM users ORDER BY id', $recovered),
        );
    }

    /**
     * An undone `UPDATE` of an *indexed* column, replayed. The first pass
     * removes the new value's index entry and adds the old one back; a
     * second pass must not try to remove the new value's entry again,
     * which is no longer there — `BTreeIndex::delete()` throws on a key
     * it cannot find, and that would make recovery fail rather than be
     * harmlessly repeated.
     */
    public function testReplayingAnUndoneUpdateOfAnIndexedColumnIsHarmless(): void
    {
        $this->executor->run('CREATE INDEX idx_users_name ON users (name)');
        $this->executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        $this->executor->run('BEGIN');
        $this->executor->run("UPDATE users SET name = 'Bob' WHERE id = 1");

        $this->database->transactions()->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        try {
            $this->executor->run('ROLLBACK');
            self::fail('Expected the sync failure to propagate.');
        } catch (RuntimeException) {
            // Expected - the undo is applied, the ROLLBACK record is not.
        }

        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $recovered = new Executor($this->database);

        self::assertSame(
            [['id' => 1, 'name' => 'Ann']],
            $this->query("SELECT * FROM users WHERE name = 'Ann'", $recovered),
        );
    }

    private function onlyRecordIdInUsers(): RecordId
    {
        foreach ($this->database->heapFile('users')->scan() as $id => $record) {
            return $id;
        }

        self::fail('Expected "users" to hold at least one row.');
    }
}

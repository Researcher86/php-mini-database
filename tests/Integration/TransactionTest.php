<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "integration tests" for transactions: durability across a
 * process boundary. `ExecutorTransactionTest` already proves `BEGIN`/
 * `COMMIT`/`ROLLBACK`/savepoints against one long-lived `Executor`; what it
 * cannot prove is what happens to the *data on disk* once that `Executor`
 * is gone - this closes the database (without ever calling `COMMIT` or
 * `ROLLBACK`, simulating a crash mid-transaction) and opens a brand new
 * `Database`/`Executor` pair against the same directory, exercising
 * `Executor::__construct()`'s own `TransactionManager::recover()` call the
 * way a restarted server actually would.
 */
final class TransactionTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /** @return list<array<string, mixed>> */
    private function query(Executor $executor, string $sql): array
    {
        $result = $executor->run($sql);
        self::assertInstanceOf(QueryResult::class, $result);

        return array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false));
    }

    public function testAnUncommittedTransactionIsUndoneAfterACrashAndRestart(): void
    {
        $database = Database::open($this->path('mydb'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');
        $executor->run('INSERT INTO t (id, n) VALUES (1, 1)');

        $executor->run('BEGIN');
        $executor->run('INSERT INTO t (id, n) VALUES (2, 2)');
        $executor->run("UPDATE t SET n = 100 WHERE id = 1");
        // Simulate a crash: the process disappears with the transaction
        // still open - no COMMIT, no ROLLBACK. close() only releases file
        // handles; it never touches the in-flight transaction.
        $database->close();

        $restarted = Database::open($this->path('mydb'));
        $restartedExecutor = new Executor($restarted);

        self::assertSame(
            [['id' => 1, 'n' => 1]],
            $this->query($restartedExecutor, 'SELECT * FROM t'),
        );

        $restarted->close();
    }

    public function testACommittedTransactionSurvivesACrashAndRestart(): void
    {
        $database = Database::open($this->path('mydb'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');

        $executor->run('BEGIN');
        $executor->run('INSERT INTO t (id, n) VALUES (1, 1)');
        $executor->run('INSERT INTO t (id, n) VALUES (2, 2)');
        $executor->run('COMMIT');
        $database->close();

        $restarted = Database::open($this->path('mydb'));
        $restartedExecutor = new Executor($restarted);

        self::assertSame(
            [['id' => 1, 'n' => 1], ['id' => 2, 'n' => 2]],
            $this->query($restartedExecutor, 'SELECT * FROM t ORDER BY id'),
        );

        $restarted->close();
    }

    public function testARollbackToASavepointBeforeACrashLeavesOnlyThePreSavepointWork(): void
    {
        $database = Database::open($this->path('mydb'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY)');

        $executor->run('BEGIN');
        $executor->run('INSERT INTO t (id) VALUES (1)');
        $executor->run('SAVEPOINT sp1');
        $executor->run('INSERT INTO t (id) VALUES (2)');
        $executor->run('ROLLBACK TO SAVEPOINT sp1');
        // Still open, still uncommitted, when the "crash" happens.
        $database->close();

        $restarted = Database::open($this->path('mydb'));
        $restartedExecutor = new Executor($restarted);

        // The whole transaction was never committed, so recovery undoes
        // everything logged for it - including the id=1 insert that came
        // before the savepoint.
        self::assertSame([], $this->query($restartedExecutor, 'SELECT * FROM t'));

        $restarted->close();
    }

    public function testMultipleRestartsEachRecoverCleanlyFromTheLastCommittedState(): void
    {
        $database = Database::open($this->path('mydb'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY)');
        $executor->run('INSERT INTO t (id) VALUES (1)');
        $database->close();

        for ($generation = 2; $generation <= 4; $generation++) {
            $db = Database::open($this->path('mydb'));
            $exec = new Executor($db);

            $exec->run('BEGIN');
            $exec->run(sprintf('INSERT INTO t (id) VALUES (%d)', $generation));
            $exec->run('COMMIT');

            $db->close();
        }

        $final = Database::open($this->path('mydb'));
        $finalExecutor = new Executor($final);

        self::assertSame(
            [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]],
            $this->query($finalExecutor, 'SELECT * FROM t ORDER BY id'),
        );

        $final->close();
    }
}

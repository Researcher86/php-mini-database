<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "integration tests (client-server)": a real
 * `bin/minidb-server` child process, talked to by *several independent*
 * `Client\Connection`s at once - what `tests/Unit/Client/ConnectionTest`
 * (one connection at a time) and `tests/Unit/Network/*` (the server driven
 * directly, never through a real socket) do not between them cover: two
 * sessions actually interleaving on the same server.
 */
final class ServerClientTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15551;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->startServer($this->path('mydb'), self::PORT);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->tearDownTemporaryDirectory();
    }

    private function connect(): Connection
    {
        return Connection::connect(new ClientConfig(port: self::PORT, connectTimeoutSeconds: 2.0, readTimeoutSeconds: 2.0));
    }

    public function testARowWrittenByOneConnectionIsVisibleToAnotherOnceCommitted(): void
    {
        $writer = $this->connect();
        $reader = $this->connect();

        $writer->execute('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');
        $writer->execute('INSERT INTO t (id, n) VALUES (1, 1)');

        self::assertSame([['id' => 1, 'n' => 1]], $reader->query('SELECT * FROM t')->fetchAll());

        $writer->close();
        $reader->close();
    }

    /**
     * DECISIONS.md's "Isolation levels are 2-phase locking, not MVCC": there
     * is no snapshot a reader falls back to, so an uncommitted write is
     * visible to every other connection the instant it lands in the heap
     * file, and disappears again if the writer rolls back - dirty reads are
     * a named, deliberate gap, not a bug, and this proves it end to end.
     */
    public function testAnUncommittedInsertIsAlreadyVisibleToAnotherConnectionAndDisappearsOnRollback(): void
    {
        $writer = $this->connect();
        $reader = $this->connect();

        $writer->execute('CREATE TABLE t (id INT PRIMARY KEY)');
        $writer->beginTransaction();
        $writer->execute('INSERT INTO t (id) VALUES (1)');

        self::assertSame([['id' => 1]], $reader->query('SELECT * FROM t')->fetchAll());

        $writer->rollback();

        self::assertSame([], $reader->query('SELECT * FROM t')->fetchAll());

        $writer->close();
        $reader->close();
    }

    /**
     * The bug DECISIONS.md's "A transaction belongs to its owning
     * connection" fixed: before it, a second connection's own bare
     * statement (no `BEGIN` of its own) silently ran *inside* whatever
     * transaction another connection had open, and that second connection
     * could then `COMMIT`/`ROLLBACK` it - ending a transaction it never
     * started, without ever having sent a `BEGIN` itself.
     */
    public function testASecondConnectionCannotJoinOrCommitAnotherConnectionsOpenTransaction(): void
    {
        $first = $this->connect();
        $second = $this->connect();

        $first->execute('CREATE TABLE t (id INT PRIMARY KEY)');
        $first->beginTransaction();
        $first->execute('INSERT INTO t (id) VALUES (1)');

        try {
            $second->execute('INSERT INTO t (id) VALUES (2)');
            self::fail('Expected the second connection to be rejected while the first transaction is open.');
        } catch (ClientException) {
            // Expected - not silently joined.
        }

        try {
            $second->commit();
            self::fail('Expected the second connection to be rejected: it never began this transaction.');
        } catch (ClientException) {
            // Expected.
        }

        // The first connection's own transaction is untouched - its COMMIT
        // still works, and only its own row made it in.
        $first->commit();

        self::assertSame([['id' => 1]], $first->query('SELECT * FROM t')->fetchAll());

        $first->close();
        $second->close();
    }

    /**
     * DECISIONS.md's "`LockManager` never waits": `TransactionManager` owns
     * exactly one current `Transaction` for the whole `Database`, so a
     * second connection's own `BEGIN` while the first is still open is
     * refused outright, server-wide, before either ever gets to a lock
     * conflict on a specific row.
     */
    public function testOnlyOneConnectionAtATimeCanHaveAnOpenTransaction(): void
    {
        $first = $this->connect();
        $second = $this->connect();

        $first->execute('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');
        $first->beginTransaction();
        $first->execute('INSERT INTO t (id, n) VALUES (1, 1)');

        try {
            $second->beginTransaction();
            self::fail('Expected the second connection to be rejected while the first transaction is open.');
        } catch (ClientException) {
            // Expected.
        }

        $first->commit();

        // Now that the first transaction is finished, the second is free
        // to open its own.
        $second->beginTransaction();
        $second->execute('INSERT INTO t (id, n) VALUES (2, 2)');
        $second->commit();

        self::assertSame(
            [['id' => 1, 'n' => 1], ['id' => 2, 'n' => 2]],
            $first->query('SELECT * FROM t ORDER BY id')->fetchAll(),
        );

        $first->close();
        $second->close();
    }

    public function testAFailedStatementDoesNotBreakTheConnectionForFurtherUse(): void
    {
        $conn = $this->connect();
        $conn->execute('CREATE TABLE t (id INT PRIMARY KEY)');

        try {
            $conn->query('SELECT * FROM no_such_table');
            self::fail('Expected a ClientException.');
        } catch (ClientException) {
            // Expected.
        }

        $conn->execute('INSERT INTO t (id) VALUES (1)');
        self::assertSame([['id' => 1]], $conn->query('SELECT * FROM t')->fetchAll());

        $conn->close();
    }

    public function testAPreparedStatementIsReusableAcrossManyExecutionsFromOneConnection(): void
    {
        $conn = $this->connect();
        $conn->execute('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');
        $conn->execute('INSERT INTO t (id, n) VALUES (1, 10), (2, 20), (3, 30)');

        $stmt = $conn->prepare('SELECT n FROM t WHERE id = ?');

        self::assertSame([['n' => 10]], $stmt->execute([1])->fetchAll());
        self::assertSame([['n' => 20]], $stmt->execute([2])->fetchAll());
        self::assertSame([['n' => 30]], $stmt->execute([3])->fetchAll());

        $conn->close();
    }

    public function testManyConnectionsCanBeOpenOnTheSameServerAtOnce(): void
    {
        $connections = [];

        for ($i = 0; $i < 10; $i++) {
            $connections[] = $this->connect();
        }

        foreach ($connections as $connection) {
            self::assertTrue($connection->isAlive());
        }

        $status = $connections[0]->showStatus()->fetch();
        self::assertNotNull($status);
        self::assertSame(10, $status['active_connections']);

        foreach ($connections as $connection) {
            $connection->close();
        }
    }
}

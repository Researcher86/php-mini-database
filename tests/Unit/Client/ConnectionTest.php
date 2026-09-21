<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Client;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `Client\Connection` end to end, against a real `bin/minidb-server`
 * child process — see `RunningServer`'s own docblock for why this needs
 * an actual second process rather than `Server::tick()` driven by hand.
 */
final class ConnectionTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15531;

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

    private function config(): ClientConfig
    {
        return new ClientConfig(port: self::PORT, connectTimeoutSeconds: 2.0, readTimeoutSeconds: 2.0);
    }

    public function testConnectSucceedsAgainstADevModeServer(): void
    {
        $conn = Connection::connect($this->config());

        self::assertTrue($conn->isAlive());

        $conn->close();
    }

    public function testQueryReturnsRows(): void
    {
        $conn = Connection::connect($this->config());

        $result = $conn->query('SELECT 1 + 1 AS two');

        self::assertSame(['two'], $result->columns());
        self::assertSame(['two' => 2], $result->fetch());

        $conn->close();
    }

    public function testExecuteReturnsTheAffectedRowCountForDml(): void
    {
        $conn = Connection::connect($this->config());

        $conn->execute('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $affected = $conn->execute("INSERT INTO users (id, name) VALUES (1, 'Ann')");

        self::assertSame(1, $affected);

        $result = $conn->query('SELECT id, name FROM users');
        self::assertSame(['id' => 1, 'name' => 'Ann'], $result->fetch());

        $conn->close();
    }

    public function testExecuteReturnsNullForDdl(): void
    {
        $conn = Connection::connect($this->config());

        $affected = $conn->execute('CREATE TABLE users (id INT PRIMARY KEY)');

        self::assertNull($affected);

        $conn->close();
    }

    public function testQueryAcceptsBoundParameters(): void
    {
        $conn = Connection::connect($this->config());
        $conn->execute('CREATE TABLE users (id INT PRIMARY KEY, age INT)');
        $conn->execute('INSERT INTO users (id, age) VALUES (1, 30)');

        $result = $conn->query('SELECT id FROM users WHERE age > ?', [18]);

        self::assertSame(['id' => 1], $result->fetch());

        $conn->close();
    }

    public function testAFailingQueryThrowsAClientExceptionCarryingTheErrorCode(): void
    {
        $conn = Connection::connect($this->config());

        try {
            $conn->query('SELECT * FROM no_such_table');
            self::fail('Expected a ClientException.');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::TABLE_NOT_FOUND, $e->errorCode);
        }

        $conn->close();
    }

    public function testPrepareExecuteAndCloseAStatement(): void
    {
        $conn = Connection::connect($this->config());
        $conn->execute('CREATE TABLE users (id INT PRIMARY KEY, age INT)');
        $conn->execute('INSERT INTO users (id, age) VALUES (1, 17), (2, 30), (3, 45)');

        $stmt = $conn->prepare('SELECT id FROM users WHERE age > ?');

        $first = $stmt->execute([18]);
        self::assertSame([['id' => 2], ['id' => 3]], $first->fetchAll());

        $second = $stmt->execute([40]);
        self::assertSame([['id' => 3]], $second->fetchAll());

        $stmt->close();

        try {
            $stmt->execute([0]);
            self::fail('Expected a ClientException after closing the statement.');
        } catch (ClientException) {
            // Expected - the id is no longer known to the server.
        }

        $conn->close();
    }

    public function testTransactionsRoundTripThroughTheClient(): void
    {
        $conn = Connection::connect($this->config());
        $conn->execute('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');

        $conn->beginTransaction();
        $conn->execute("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $conn->savepoint('sp1');
        $conn->execute("INSERT INTO users (id, name) VALUES (2, 'Bob')");
        $conn->rollback();

        self::assertSame([], $conn->query('SELECT id, name FROM users')->fetchAll());

        $conn->beginTransaction();
        $conn->execute("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $conn->commit();

        self::assertSame([['id' => 1, 'name' => 'Ann']], $conn->query('SELECT id, name FROM users')->fetchAll());

        $conn->close();
    }

    public function testCloseIsIdempotentAndIsAliveReturnsFalseAfterward(): void
    {
        $conn = Connection::connect($this->config());

        $conn->close();
        $conn->close();

        self::assertFalse($conn->isAlive());
    }

    public function testReconnectRestoresAUsableConnectionAfterClose(): void
    {
        $conn = Connection::connect($this->config());
        $conn->close();

        self::assertFalse($conn->isAlive());

        $conn->reconnect();

        self::assertTrue($conn->isAlive());

        $conn->close();
    }

    public function testConnectingToAClosedPortFailsWithAClientException(): void
    {
        $config = new ClientConfig(port: self::PORT + 1, connectTimeoutSeconds: 1.0);

        $this->expectException(ClientException::class);

        Connection::connect($config);
    }
}

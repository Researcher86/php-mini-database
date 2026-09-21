<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Client;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Network\Auth\UserStore;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `Connection::connect()`'s challenge-response handshake, against a real
 * `bin/minidb-server` child process with `authEnabled` on - the same
 * `Network\Auth\PasswordHash`/`ScramChallenge` the server itself uses, now
 * exercised from the other side by `Connection`.
 */
final class ConnectionAuthTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15532;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();

        $dataDirectory = $this->path('mydb');
        (new FileSystem())->ensureDirectory($dataDirectory);
        (new UserStore($dataDirectory . '/users.json'))->create('alice', 'secret');

        $this->startServer($dataDirectory, self::PORT, ['MINIDB_AUTH_ENABLED' => 'true']);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->tearDownTemporaryDirectory();
    }

    public function testCorrectCredentialsAuthenticateSuccessfully(): void
    {
        $config = new ClientConfig(port: self::PORT, user: 'alice', password: 'secret', connectTimeoutSeconds: 2.0, readTimeoutSeconds: 2.0);

        $conn = Connection::connect($config);

        self::assertTrue($conn->isAlive());
        self::assertSame(['ok' => 1], $conn->query('SELECT 1 AS ok')->fetch());

        $conn->close();
    }

    public function testWrongPasswordFailsWithAnAuthFailedClientException(): void
    {
        $config = new ClientConfig(port: self::PORT, user: 'alice', password: 'wrong', connectTimeoutSeconds: 2.0, readTimeoutSeconds: 2.0);

        try {
            Connection::connect($config);
            self::fail('Expected a ClientException.');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::AUTH_FAILED, $e->errorCode);
        }
    }

    public function testAnUnknownUsernameFailsTheSameGenericWay(): void
    {
        $config = new ClientConfig(port: self::PORT, user: 'nobody', password: 'whatever', connectTimeoutSeconds: 2.0, readTimeoutSeconds: 2.0);

        try {
            Connection::connect($config);
            self::fail('Expected a ClientException.');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::AUTH_FAILED, $e->errorCode);
        }
    }
}

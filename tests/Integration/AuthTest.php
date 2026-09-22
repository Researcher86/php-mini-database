<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Cli\Command\UserCommand;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Tests\Support\MemoryStream;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "integration tests" for authentication: the whole path a
 * real deployment actually uses - `bin/minidb user add` (not
 * `Network\Auth\UserStore` constructed by hand, the way
 * `ConnectionAuthTest` does it) creating the account a real
 * `bin/minidb-server` with `authEnabled` then checks - plus the one thing
 * no other test covers, `LoginThrottle`'s lockout after repeated failures.
 */
final class AuthTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;
    use MemoryStream;

    private const PORT = 15561;

    private string $dataDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->dataDirectory = $this->path('mydb');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->tearDownTemporaryDirectory();
    }

    private function createUserViaCli(string $username, string $password): void
    {
        $exitCode = (new UserCommand())->run(
            'add',
            [$username],
            ['data' => [$this->dataDirectory], 'password' => [$password]],
            $this->memoryStream(),
            $this->memoryStream(),
        );

        self::assertSame(0, $exitCode);
    }

    private function config(string $user, string $password): ClientConfig
    {
        return new ClientConfig(port: self::PORT, user: $user, password: $password, connectTimeoutSeconds: 2.0, readTimeoutSeconds: 2.0);
    }

    public function testAUserCreatedThroughTheCliCanAuthenticateAgainstARealServer(): void
    {
        $this->createUserViaCli('alice', 'secret');
        $this->startServer($this->dataDirectory, self::PORT, ['MINIDB_AUTH_ENABLED' => 'true']);

        $conn = Connection::connect($this->config('alice', 'secret'));

        self::assertSame(['ok' => 1], $conn->query('SELECT 1 AS ok')->fetch());

        $conn->close();
    }

    public function testRepeatedFailedLoginsLockTheAccountOutEvenWithTheCorrectPasswordAfterward(): void
    {
        $this->createUserViaCli('alice', 'secret');
        $this->startServer($this->dataDirectory, self::PORT, ['MINIDB_AUTH_ENABLED' => 'true']);

        // LoginThrottle's default is 5 free failures before the 6th locks
        // the account out - see Network\Auth\LoginThrottle.
        for ($i = 0; $i < 6; $i++) {
            try {
                Connection::connect($this->config('alice', 'wrong'));
                self::fail('Expected a ClientException for a wrong password.');
            } catch (ClientException $e) {
                self::assertSame(ErrorCode::AUTH_FAILED, $e->errorCode);
            }
        }

        // The account is now locked for a short delay, so even the
        // correct password is rejected - with a distinguishable reason.
        try {
            Connection::connect($this->config('alice', 'secret'));
            self::fail('Expected the account to still be locked out.');
        } catch (ClientException $e) {
            self::assertStringContainsString('Too many failed attempts', $e->getMessage());
        }

        // LoginThrottle's base delay for the very first lockout is 1
        // second; wait it out and confirm the correct password now works.
        usleep(1_100_000);

        $conn = Connection::connect($this->config('alice', 'secret'));
        self::assertTrue($conn->isAlive());
        $conn->close();
    }

    public function testARemovedUserCanNoLongerAuthenticate(): void
    {
        $this->createUserViaCli('alice', 'secret');
        $this->startServer($this->dataDirectory, self::PORT, ['MINIDB_AUTH_ENABLED' => 'true']);

        Connection::connect($this->config('alice', 'secret'))->close();

        $this->stopServer();

        $exitCode = (new UserCommand())->run(
            'remove',
            ['alice'],
            ['data' => [$this->dataDirectory]],
            $this->memoryStream(),
            $this->memoryStream(),
        );
        self::assertSame(0, $exitCode);

        $this->startServer($this->dataDirectory, self::PORT, ['MINIDB_AUTH_ENABLED' => 'true']);

        try {
            Connection::connect($this->config('alice', 'secret'));
            self::fail('Expected authentication to fail for a removed user.');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::AUTH_FAILED, $e->errorCode);
        }
    }

    public function testAServerWithAuthDisabledAcceptsAnyCredentials(): void
    {
        $this->startServer($this->dataDirectory, self::PORT, ['MINIDB_AUTH_ENABLED' => 'false']);

        $conn = Connection::connect($this->config('whoever', 'whatever'));

        self::assertSame(['ok' => 1], $conn->query('SELECT 1 AS ok')->fetch());

        $conn->close();
    }
}

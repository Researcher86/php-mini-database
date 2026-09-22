<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Client;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\ConnectionPool;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ConnectionPoolTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15533;

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

    public function testAcquireCreatesUpToTheConfiguredLimitThenThrows(): void
    {
        $pool = new ConnectionPool($this->config(), maxConnections: 2);

        $first = $pool->acquire();
        $second = $pool->acquire();

        self::assertTrue($first->isAlive());
        self::assertTrue($second->isAlive());

        try {
            $pool->acquire();
            self::fail('Expected a ClientException.');
        } catch (ClientException $e) {
            self::assertStringContainsString('exhausted', $e->getMessage());
        }

        $pool->close();
    }

    public function testReleasingAConnectionMakesItAvailableForReuse(): void
    {
        $pool = new ConnectionPool($this->config(), maxConnections: 1);

        $first = $pool->acquire();
        $pool->release($first);

        $second = $pool->acquire();

        self::assertSame($first, $second);

        $pool->close();
    }

    public function testAcquireReconnectsADeadIdleConnectionInsteadOfHandingItOutBroken(): void
    {
        $pool = new ConnectionPool($this->config(), maxConnections: 1);

        $first = $pool->acquire();
        $first->close();
        $pool->release($first);

        $reused = $pool->acquire();

        self::assertSame($first, $reused);
        self::assertTrue($reused->isAlive());

        $pool->close();
    }

    public function testCloseClosesEveryIdleConnection(): void
    {
        $pool = new ConnectionPool($this->config(), maxConnections: 2);

        $connection = $pool->acquire();
        $pool->release($connection);

        $pool->close();

        self::assertFalse($connection->isAlive());
    }

    public function testAFreshConnectionCanBeAcquiredAfterThePoolIsClosed(): void
    {
        $pool = new ConnectionPool($this->config(), maxConnections: 1);

        $connection = $pool->acquire();
        $pool->release($connection);
        $pool->close();

        $fresh = $pool->acquire();

        self::assertTrue($fresh->isAlive());
        self::assertNotSame($connection, $fresh);

        $pool->close();
    }
}

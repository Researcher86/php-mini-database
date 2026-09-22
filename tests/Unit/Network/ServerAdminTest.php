<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Kill;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Protocol\Message\ShowConnections;
use PhpMiniDatabase\Network\Protocol\Message\ShowStatus;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `SHOW_STATUS`/`SHOW_CONNECTIONS`/`KILL` end to end — same "drive
 * `tick()` by hand" strategy as `ServerTest`.
 */
final class ServerAdminTest extends TestCase
{
    use TemporaryDirectory;

    private Server $server;

    /** @var array<int, resource> */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->server = new Server(new ServerConfig($this->path('mydb'), port: 0));
        $this->server->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $this->server->shutdown();
        $this->tearDownTemporaryDirectory();
    }

    /** @return resource */
    private function connect(): mixed
    {
        $client = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($client, (string) $errorMessage);
        stream_set_blocking($client, false);
        $this->clients[] = $client;

        return $client;
    }

    /** @param resource $client */
    private function send(mixed $client, Message $message): void
    {
        fwrite($client, (new Codec())->encode($message)->toBytes());
    }

    /** @param resource $client */
    private function receive(mixed $client): Message
    {
        $reader = new FrameReader();
        $codec = new Codec();

        for ($i = 0; $i < 100; $i++) {
            $this->server->tick(0.05);

            $chunk = @fread($client, 65536);

            if ($chunk !== false && $chunk !== '') {
                $reader->feed($chunk);
            }

            $frame = $reader->next();

            if ($frame !== null) {
                return $codec->decode($frame);
            }
        }

        self::fail('Timed out waiting for a response from the server.');
    }

    /** @return resource */
    private function handshake(): mixed
    {
        $client = $this->connect();
        $this->send($client, new Hello(1, 'phpunit', '1.0'));
        self::assertInstanceOf(HelloAck::class, $this->receive($client));

        return $client;
    }

    public function testShowStatusReportsCounters(): void
    {
        $client = $this->handshake();

        $this->send($client, new Query('SELECT 1'));
        $this->receive($client);
        $this->send($client, new Query('SELECT * FROM no_such_table'));
        $this->receive($client);

        $this->send($client, new ShowStatus());
        $status = $this->receive($client);

        self::assertInstanceOf(QueryResultMessage::class, $status);
        self::assertSame(
            ['uptime_seconds', 'active_connections', 'total_connections', 'total_queries', 'total_errors', 'queries_per_second'],
            $status->columns,
        );
        self::assertCount(1, $status->rows);
        [$uptime, $active, $totalConnections, $totalQueries, $totalErrors, $qps] = $status->rows[0];
        self::assertGreaterThanOrEqual(0, $uptime);
        self::assertSame(1, $active);
        self::assertGreaterThanOrEqual(1, $totalConnections);
        // ShowStatus itself does not count as a query - only the two Query
        // messages sent before it do.
        self::assertSame(2, $totalQueries);
        self::assertSame(1, $totalErrors);
        self::assertIsFloat($qps);
    }

    public function testShowConnectionsListsEveryOpenSession(): void
    {
        $first = $this->handshake();
        $second = $this->handshake();

        $this->send($first, new ShowConnections());
        $result = $this->receive($first);

        self::assertInstanceOf(QueryResultMessage::class, $result);
        self::assertSame(['id', 'username', 'connected_at', 'authenticated'], $result->columns);
        self::assertCount(2, $result->rows);

        foreach ($result->rows as $row) {
            [, $username, $connectedAt, $authenticated] = $row;
            self::assertNull($username);
            self::assertTrue($authenticated);
            self::assertNotNull($connectedAt);
        }
    }

    public function testKillClosesTheTargetSession(): void
    {
        // Asked right after connecting, while it is still the only open
        // session, SHOW_CONNECTIONS' one row names the victim's own id -
        // a clean way to learn it without guessing at id assignment order.
        $victim = $this->handshake();
        $this->send($victim, new ShowConnections());
        $selfReport = $this->receive($victim);
        self::assertInstanceOf(QueryResultMessage::class, $selfReport);
        self::assertCount(1, $selfReport->rows);
        $victimId = $selfReport->rows[0][0];

        $killer = $this->handshake();

        $this->send($killer, new Kill($victimId));
        $reply = $this->receive($killer);
        self::assertInstanceOf(QueryResultMessage::class, $reply);

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 1; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(1, $this->server->sessionCount());
    }

    public function testKillingAnUnknownConnectionIsAQueryError(): void
    {
        $client = $this->handshake();

        $this->send($client, new Kill(999999));

        self::assertInstanceOf(QueryError::class, $this->receive($client));
    }

    public function testAdminCommandsBeforeTheHandshakeCloseTheConnection(): void
    {
        $client = $this->connect();

        $this->send($client, new ShowStatus());

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
    }

    public function testReloadRaisesTheMaxConnectionsLimit(): void
    {
        $this->server->shutdown();
        $this->server = new Server(new ServerConfig($this->path('mydb2'), port: 0, maxConnections: 1));
        $this->server->start();

        $this->handshake();

        $refused = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($refused, (string) $errorMessage);
        stream_set_blocking($refused, false);
        $this->clients[] = $refused;

        for ($i = 0; $i < 10; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(1, $this->server->sessionCount());

        putenv('MINIDB_MAX_CONNECTIONS=2');

        try {
            $this->server->reload();

            $second = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
            self::assertNotFalse($second, (string) $errorMessage);
            stream_set_blocking($second, false);
            $this->clients[] = $second;

            for ($i = 0; $i < 10; $i++) {
                $this->server->tick(0.05);
            }

            self::assertSame(2, $this->server->sessionCount());
        } finally {
            putenv('MINIDB_MAX_CONNECTIONS');
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Begin;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Ping;
use PhpMiniDatabase\Network\Protocol\Message\Pong;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use PhpMiniDatabase\Tests\Support\FakeClock;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `ServerConfig::$idleTimeoutSeconds` end to end — same "drive `tick()`
 * by hand" strategy as `ServerTest`, with a `FakeClock` standing in for
 * the passage of time so no test here ever actually waits five minutes
 * (or one second) for a connection to go idle.
 */
final class ServerIdleTimeoutTest extends TestCase
{
    use TemporaryDirectory;

    private Server $server;

    private FakeClock $clock;

    private Codec $codec;

    /** @var array<int, resource> */
    private array $clients = [];

    /** @var array<int, FrameReader> keyed by the client's resource id */
    private array $readers = [];

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->codec = new Codec();
        $this->clock = new FakeClock();
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

    private function startServer(int $idleTimeoutSeconds): void
    {
        $this->server = new Server(
            new ServerConfig($this->path('mydb'), port: 0, idleTimeoutSeconds: $idleTimeoutSeconds),
            new Logger($this->path('server.log')),
            $this->clock,
        );
        $this->server->start();
    }

    /** @return resource a client that has completed the handshake */
    private function connect(): mixed
    {
        $client = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($client, (string) $errorMessage);
        stream_set_blocking($client, false);
        $this->clients[] = $client;
        $this->readers[(int) $client] = new FrameReader();

        self::assertInstanceOf(HelloAck::class, $this->roundTrip($client, new Hello(1, 'phpunit', '1.0')));

        return $client;
    }

    /** @param resource $client */
    private function roundTrip(mixed $client, Message $message): Message
    {
        fwrite($client, $this->codec->encode($message)->toBytes());
        $reader = $this->readers[(int) $client];

        for ($i = 0; $i < 100; $i++) {
            $this->server->tick(0.05);

            $chunk = @fread($client, 65536);

            if ($chunk !== false && $chunk !== '') {
                $reader->feed($chunk);
            }

            $frame = $reader->next();

            if ($frame !== null) {
                return $this->codec->decode($frame);
            }
        }

        self::fail('Timed out waiting for a response from the server.');
    }

    /** @param resource $client */
    private function assertClosedByServer(mixed $client): void
    {
        for ($i = 0; $i < 100; $i++) {
            $chunk = @fread($client, 65536);

            if (($chunk === '' || $chunk === false) && feof($client)) {
                return;
            }

            usleep(10_000);
        }

        self::fail('The server never closed the connection.');
    }

    public function testAConnectionIdleLongerThanTheTimeoutIsClosed(): void
    {
        $this->startServer(idleTimeoutSeconds: 300);
        $client = $this->connect();

        $this->clock->advanceBy(301);
        $this->server->tick(0.05);

        self::assertSame(0, $this->server->sessionCount());
        $this->assertClosedByServer($client);
        self::assertStringContainsString('idle for more than 300 seconds', (string) file_get_contents($this->path('server.log')));
    }

    public function testAConnectionIsNotClosedBeforeTheTimeoutElapses(): void
    {
        $this->startServer(idleTimeoutSeconds: 300);
        $this->connect();

        $this->clock->advanceBy(299);
        $this->server->tick(0.05);

        self::assertSame(1, $this->server->sessionCount());
    }

    public function testAnyMessageFromTheClientRestartsTheIdleClock(): void
    {
        $this->startServer(idleTimeoutSeconds: 300);
        $client = $this->connect();

        $this->clock->advanceBy(200);
        self::assertInstanceOf(Pong::class, $this->roundTrip($client, new Ping()));
        $this->clock->advanceBy(200);
        $this->server->tick(0.05);

        // 400 seconds since the connection opened, but only 200 since it
        // last said anything - still well inside the timeout.
        self::assertSame(1, $this->server->sessionCount());
    }

    public function testOnlyTheIdleConnectionIsClosed(): void
    {
        $this->startServer(idleTimeoutSeconds: 300);
        $idle = $this->connect();
        $this->clock->advanceBy(200);
        $active = $this->connect();

        $this->clock->advanceBy(150);
        $this->server->tick(0.05);

        self::assertSame(1, $this->server->sessionCount());
        $this->assertClosedByServer($idle);
        self::assertInstanceOf(Pong::class, $this->roundTrip($active, new Ping()));
    }

    public function testAZeroTimeoutDisablesIdleClosing(): void
    {
        $this->startServer(idleTimeoutSeconds: 0);
        $this->connect();

        $this->clock->advanceBy(86_400);
        $this->server->tick(0.05);

        self::assertSame(1, $this->server->sessionCount());
    }

    public function testAnIdleConnectionMidTransactionIsRolledBackAndFreesTheSingleWriter(): void
    {
        $this->startServer(idleTimeoutSeconds: 300);
        $idle = $this->connect();
        $this->roundTrip($idle, new Query('CREATE TABLE users (id INT PRIMARY KEY)'));
        $this->roundTrip($idle, new Begin());
        $this->roundTrip($idle, new Query('INSERT INTO users (id) VALUES (1)'));

        $this->clock->advanceBy(301);
        $this->server->tick(0.05);
        self::assertSame(0, $this->server->sessionCount());

        $other = $this->connect();
        self::assertInstanceOf(QueryResultMessage::class, $this->roundTrip($other, new Begin()));
        $selected = $this->roundTrip($other, new Query('SELECT id FROM users'));
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([], $selected->rows);
    }
}

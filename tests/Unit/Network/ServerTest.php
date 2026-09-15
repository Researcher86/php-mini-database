<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Goodbye;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Ping;
use PhpMiniDatabase\Network\Protocol\Message\Pong;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The server end to end, over a real TCP socket — `Server::tick()` is
 * called by hand, exactly as `Server::run()`'s loop would, but paced by
 * this test instead of by a blocking `stream_select()` timeout, so nothing
 * here needs a second process or a thread.
 */
final class ServerTest extends TestCase
{
    use TemporaryDirectory;

    private Server $server;

    /** @var resource */
    private mixed $client;

    private Codec $codec;

    private FrameReader $reader;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->server = new Server(new ServerConfig($this->path('mydb'), port: 0));
        $this->server->start();
        $this->codec = new Codec();
        $this->reader = new FrameReader();
    }

    protected function tearDown(): void
    {
        if (isset($this->client) && is_resource($this->client)) {
            fclose($this->client);
        }

        $this->server->shutdown();
        $this->tearDownTemporaryDirectory();
    }

    private function connect(): void
    {
        $address = 'tcp://' . $this->server->localAddress();
        $client = @stream_socket_client($address, $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($client, $errorMessage);
        stream_set_blocking($client, false);
        $this->client = $client;
    }

    private function sendToServer(Message $message): void
    {
        fwrite($this->client, $this->codec->encode($message)->toBytes());
    }

    private function readFromServer(): Message
    {
        for ($i = 0; $i < 100; $i++) {
            $this->server->tick(0.05);

            $chunk = @fread($this->client, 65536);

            if ($chunk !== false && $chunk !== '') {
                $this->reader->feed($chunk);
            }

            $frame = $this->reader->next();

            if ($frame !== null) {
                return $this->codec->decode($frame);
            }
        }

        self::fail('Timed out waiting for a response from the server.');
    }

    private function handshake(): void
    {
        $this->connect();
        $this->sendToServer(new Hello(1, 'phpunit', '1.0'));
        $ack = $this->readFromServer();
        self::assertInstanceOf(HelloAck::class, $ack);
    }

    public function testAClientCanConnectAndCompleteTheHandshake(): void
    {
        $this->handshake();

        self::assertSame(1, $this->server->sessionCount());
    }

    public function testASelectQueryReturnsItsRows(): void
    {
        $this->handshake();

        $this->sendToServer(new Query("SELECT 1 + 1 AS two"));
        $result = $this->readFromServer();

        self::assertInstanceOf(QueryResultMessage::class, $result);
        self::assertSame(['two'], $result->columns);
        self::assertSame([[2]], $result->rows);
    }

    public function testDdlAndDmlWorkAcrossSeparateQueries(): void
    {
        $this->handshake();

        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (1, 'Ann')"));
        $inserted = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $inserted);
        self::assertSame(['affected_rows'], $inserted->columns);
        self::assertSame([[1]], $inserted->rows);

        $this->sendToServer(new Query('SELECT id, name FROM users'));
        $selected = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([[1, 'Ann']], $selected->rows);
    }

    public function testAQueryParameterIsBoundCorrectly(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, age INT)'));
        $this->readFromServer();
        $this->sendToServer(new Query('INSERT INTO users (id, age) VALUES (1, 30)'));
        $this->readFromServer();

        $this->sendToServer(new Query('SELECT id FROM users WHERE age > ?', [18]));
        $result = $this->readFromServer();

        self::assertInstanceOf(QueryResultMessage::class, $result);
        self::assertSame([[1]], $result->rows);
    }

    public function testAFailingQueryReturnsAQueryError(): void
    {
        $this->handshake();

        $this->sendToServer(new Query('SELECT * FROM no_such_table'));
        $error = $this->readFromServer();

        self::assertInstanceOf(QueryError::class, $error);
    }

    public function testPingIsAnsweredWithPong(): void
    {
        $this->handshake();

        $this->sendToServer(new Ping());

        self::assertInstanceOf(Pong::class, $this->readFromServer());
    }

    public function testGoodbyeClosesTheSessionServerSide(): void
    {
        $this->handshake();
        self::assertSame(1, $this->server->sessionCount());

        $this->sendToServer(new Goodbye());

        // The server has nothing to send back for GOODBYE - drive a couple
        // of ticks so it has a chance to notice the frame and close its
        // side, then check the session accounting instead of the socket.
        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
    }

    public function testAQueryBeforeTheHandshakeClosesTheConnection(): void
    {
        $this->connect();

        $this->sendToServer(new Query('SELECT 1'));

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
    }

    public function testDisconnectingTheClientIsNoticedByTheServer(): void
    {
        $this->handshake();
        self::assertSame(1, $this->server->sessionCount());

        fclose($this->client);
        unset($this->client);

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
    }

    public function testASecondSessionIsRefusedOnceMaxConnectionsIsReached(): void
    {
        $this->server->shutdown();
        $this->server = new Server(new ServerConfig($this->path('mydb2'), port: 0, maxConnections: 1));
        $this->server->start();

        $this->handshake();

        $second = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($second, $errorMessage);
        stream_set_blocking($second, false);

        for ($i = 0; $i < 10; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(1, $this->server->sessionCount());
        fclose($second);
    }

    public function testASecondSessionSeesTheFirstsOpenTransactionAsTheSingleWriter(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY)'));
        $this->readFromServer();
        $this->sendToServer(new Query('BEGIN'));
        $this->readFromServer();

        $secondCodec = new Codec();
        $secondReader = new FrameReader();
        $secondClient = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($secondClient, $errorMessage);
        stream_set_blocking($secondClient, false);

        fwrite($secondClient, $secondCodec->encode(new Hello(1, 'phpunit-2', '1.0'))->toBytes());
        $this->pumpUntilFrame($secondReader, $secondClient);

        fwrite($secondClient, $secondCodec->encode(new Query('BEGIN'))->toBytes());
        $frame = $this->pumpUntilFrame($secondReader, $secondClient);
        $response = $secondCodec->decode($frame);

        self::assertInstanceOf(QueryError::class, $response);

        fclose($secondClient);
    }

    /** @param resource $client */
    private function pumpUntilFrame(FrameReader $reader, mixed $client): Frame
    {
        for ($i = 0; $i < 100; $i++) {
            $this->server->tick(0.05);
            $chunk = @fread($client, 65536);

            if ($chunk !== false && $chunk !== '') {
                $reader->feed($chunk);
            }

            $frame = $reader->next();

            if ($frame !== null) {
                return $frame;
            }
        }

        self::fail('Timed out waiting for a response from the server.');
    }
}

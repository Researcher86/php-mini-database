<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Begin;
use PhpMiniDatabase\Network\Protocol\Message\Commit;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Protocol\Message\Rollback;
use PhpMiniDatabase\Network\Protocol\Message\Savepoint;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PhpMiniDatabase\Transaction\IsolationLevel;
use PHPUnit\Framework\TestCase;

/**
 * `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` as typed wire messages, end to
 * end — the same "drive `tick()` by hand" strategy as `ServerTest`.
 */
final class ServerTransactionTest extends TestCase
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

    public function testBeginCommitAndRollbackAllRoundTrip(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        $this->readFromServer();

        $this->sendToServer(new Begin());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (1, 'Ann')"));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Commit());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT id, name FROM users'));
        $selected = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([[1, 'Ann']], $selected->rows);
    }

    public function testARollbackUndoesEverythingSinceBegin(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        $this->readFromServer();

        $this->sendToServer(new Begin());
        $this->readFromServer();
        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (1, 'Ann')"));
        $this->readFromServer();
        $this->sendToServer(new Rollback());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT id, name FROM users'));
        $selected = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([], $selected->rows);
    }

    public function testBeginAcceptsAnExplicitIsolationLevel(): void
    {
        $this->handshake();

        $this->sendToServer(new Begin(IsolationLevel::SERIALIZABLE));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Commit());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
    }

    public function testSavepointAndRollbackToSavepointWorkTogetherOverTheWire(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        $this->readFromServer();

        $this->sendToServer(new Begin());
        $this->readFromServer();
        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (1, 'Ann')"));
        $this->readFromServer();

        $this->sendToServer(new Savepoint('sp1'));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (2, 'Bob')"));
        $this->readFromServer();

        // ROLLBACK TO SAVEPOINT has no typed wire message (PLAN.md's own
        // message table does not define one) - it stays reachable as plain
        // SQL text through Query, exactly like before this phase.
        $this->sendToServer(new Query('ROLLBACK TO SAVEPOINT sp1'));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Commit());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT id, name FROM users'));
        $selected = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([[1, 'Ann']], $selected->rows);
    }

    public function testASecondBeginWhileOneIsOpenIsAQueryError(): void
    {
        $this->handshake();

        $this->sendToServer(new Begin());
        $this->readFromServer();

        $this->sendToServer(new Begin());
        self::assertInstanceOf(QueryError::class, $this->readFromServer());

        $this->sendToServer(new Rollback());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
    }

    public function testCommitWithoutABeginIsAQueryError(): void
    {
        $this->handshake();

        $this->sendToServer(new Commit());

        self::assertInstanceOf(QueryError::class, $this->readFromServer());
    }

    public function testDisconnectingMidTransactionRollsItBackAndFreesTheSingleWriter(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        $this->readFromServer();

        $this->sendToServer(new Begin());
        $this->readFromServer();
        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (1, 'Ann')"));
        $this->readFromServer();

        fclose($this->client);
        unset($this->client);

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());

        // A fresh connection can BEGIN and see nothing was ever committed -
        // proof the abandoned transaction was rolled back, not just
        // forgotten about while still holding the single writer.
        $secondCodec = new Codec();
        $secondReader = new FrameReader();
        $secondClient = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($secondClient, $errorMessage);
        stream_set_blocking($secondClient, false);

        fwrite($secondClient, $secondCodec->encode(new Hello(1, 'phpunit-2', '1.0'))->toBytes());
        $this->pumpUntilFrame($secondReader, $secondClient);

        fwrite($secondClient, $secondCodec->encode(new Begin())->toBytes());
        $beginFrame = $this->pumpUntilFrame($secondReader, $secondClient);
        self::assertInstanceOf(QueryResultMessage::class, $secondCodec->decode($beginFrame));

        fwrite($secondClient, $secondCodec->encode(new Query('SELECT id, name FROM users'))->toBytes());
        $selectFrame = $this->pumpUntilFrame($secondReader, $secondClient);
        $selected = $secondCodec->decode($selectFrame);
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([], $selected->rows);

        fclose($secondClient);
    }

    public function testDisconnectingOutsideATransactionDoesNotAffectAnotherSessionsOpenOne(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        $this->readFromServer();
        $this->sendToServer(new Begin());
        $this->readFromServer();
        $this->sendToServer(new Query("INSERT INTO users (id, name) VALUES (1, 'Ann')"));
        $this->readFromServer();

        $secondCodec = new Codec();
        $secondReader = new FrameReader();
        $secondClient = @stream_socket_client('tcp://' . $this->server->localAddress(), $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($secondClient, $errorMessage);
        stream_set_blocking($secondClient, false);

        fwrite($secondClient, $secondCodec->encode(new Hello(1, 'phpunit-2', '1.0'))->toBytes());
        $this->pumpUntilFrame($secondReader, $secondClient);
        fwrite($secondClient, $secondCodec->encode(new Query('SELECT 1'))->toBytes());
        $this->pumpUntilFrame($secondReader, $secondClient);

        fclose($secondClient);

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 1; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(1, $this->server->sessionCount());

        $this->sendToServer(new Query('SELECT id, name FROM users'));
        $selected = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([[1, 'Ann']], $selected->rows);

        $this->sendToServer(new Commit());
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
    }

    public function testBeginBeforeTheHandshakeClosesTheConnection(): void
    {
        $this->connect();

        $this->sendToServer(new Begin());

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
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

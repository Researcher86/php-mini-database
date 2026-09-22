<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\CloseStatement;
use PhpMiniDatabase\Network\Protocol\Message\Execute;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Prepare;
use PhpMiniDatabase\Network\Protocol\Message\PrepareOk;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `PREPARE`/`EXECUTE`/`CLOSE_STMT` end to end, over a real TCP socket —
 * the same "drive `tick()` by hand" strategy as `ServerTest`.
 */
final class ServerPreparedStatementTest extends TestCase
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
        $this->server = new Server(new ServerConfig($this->path('mydb'), port: 0, maxPreparedStatements: 3));
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
        self::assertNotFalse($client, (string) $errorMessage);
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

    private function prepare(string $sql): int
    {
        $this->sendToServer(new Prepare($sql));
        $ack = $this->readFromServer();
        self::assertInstanceOf(PrepareOk::class, $ack);

        return $ack->statementId;
    }

    public function testAPreparedSelectCanBeExecutedRepeatedlyWithDifferentParameters(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, age INT)'));
        $this->readFromServer();
        $this->sendToServer(new Query("INSERT INTO users (id, age) VALUES (1, 17), (2, 30), (3, 45)"));
        $this->readFromServer();

        $statementId = $this->prepare('SELECT id FROM users WHERE age > ?');

        $this->sendToServer(new Execute($statementId, [18]));
        $first = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $first);
        self::assertSame([[2], [3]], $first->rows);

        $this->sendToServer(new Execute($statementId, [40]));
        $second = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $second);
        self::assertSame([[3]], $second->rows);
    }

    public function testAPreparedInsertCanBeExecutedMultipleTimes(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))'));
        $this->readFromServer();

        $statementId = $this->prepare('INSERT INTO users (id, name) VALUES (?, ?)');

        $this->sendToServer(new Execute($statementId, [1, 'Ann']));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
        $this->sendToServer(new Execute($statementId, [2, 'Bob']));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT id, name FROM users ORDER BY id'));
        $selected = $this->readFromServer();
        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([[1, 'Ann'], [2, 'Bob']], $selected->rows);
    }

    public function testAMaliciousParameterValueIsNeverInterpretedAsSql(): void
    {
        $this->handshake();
        $this->sendToServer(new Query('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(80))'));
        $this->readFromServer();

        $statementId = $this->prepare('INSERT INTO users (id, name) VALUES (?, ?)');

        $payload = "x'); DROP TABLE users; --";
        $this->sendToServer(new Execute($statementId, [1, $payload]));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT id, name FROM users'));
        $selected = $this->readFromServer();

        self::assertInstanceOf(QueryResultMessage::class, $selected);
        self::assertSame([[1, $payload]], $selected->rows);
    }

    public function testPrepareWithInvalidSqlReturnsAQueryError(): void
    {
        $this->handshake();

        $this->sendToServer(new Prepare('SELECT FROM WHERE'));

        self::assertInstanceOf(QueryError::class, $this->readFromServer());
    }

    public function testExecutingAnUnknownStatementIdReturnsAQueryError(): void
    {
        $this->handshake();

        $this->sendToServer(new Execute(999));

        self::assertInstanceOf(QueryError::class, $this->readFromServer());
    }

    public function testExecutingAClosedStatementReturnsAQueryError(): void
    {
        $this->handshake();
        $statementId = $this->prepare('SELECT 1');

        $this->sendToServer(new CloseStatement($statementId));
        $this->sendToServer(new Execute($statementId));

        self::assertInstanceOf(QueryError::class, $this->readFromServer());
    }

    public function testClosingAnUnknownStatementIsSilentlyIgnored(): void
    {
        $this->handshake();

        $this->sendToServer(new CloseStatement(999));

        // No reply is defined for CLOSE_STMT - prove the connection is
        // still alive and usable instead of waiting on a message.
        $this->sendToServer(new Query('SELECT 1'));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
    }

    public function testTheMaxPreparedStatementsLimitIsEnforcedPerSession(): void
    {
        $this->handshake();

        $this->prepare('SELECT 1');
        $this->prepare('SELECT 2');
        $this->prepare('SELECT 3');

        $this->sendToServer(new Prepare('SELECT 4'));
        self::assertInstanceOf(QueryError::class, $this->readFromServer());
    }

    public function testClosingAStatementFreesUpRoomUnderTheLimit(): void
    {
        $this->handshake();

        $first = $this->prepare('SELECT 1');
        $this->prepare('SELECT 2');
        $this->prepare('SELECT 3');

        $this->sendToServer(new CloseStatement($first));
        $this->sendToServer(new Prepare('SELECT 4'));

        self::assertInstanceOf(PrepareOk::class, $this->readFromServer());
    }

    public function testPrepareBeforeTheHandshakeClosesTheConnection(): void
    {
        $this->connect();

        $this->sendToServer(new Prepare('SELECT 1'));

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Protocol;

use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Auth;
use PhpMiniDatabase\Network\Protocol\Message\AuthFail;
use PhpMiniDatabase\Network\Protocol\Message\AuthOk;
use PhpMiniDatabase\Network\Protocol\Message\Begin;
use PhpMiniDatabase\Network\Protocol\Message\Cancel;
use PhpMiniDatabase\Network\Protocol\Message\CloseStatement;
use PhpMiniDatabase\Network\Protocol\Message\Commit;
use PhpMiniDatabase\Network\Protocol\Message\CopyIn;
use PhpMiniDatabase\Network\Protocol\Message\Execute;
use PhpMiniDatabase\Network\Protocol\Message\Goodbye;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Kill;
use PhpMiniDatabase\Network\Protocol\Message\Ping;
use PhpMiniDatabase\Network\Protocol\Message\Pong;
use PhpMiniDatabase\Network\Protocol\Message\Prepare;
use PhpMiniDatabase\Network\Protocol\Message\PrepareOk;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Protocol\Message\Rollback;
use PhpMiniDatabase\Network\Protocol\Message\Savepoint;
use PhpMiniDatabase\Network\Protocol\Message\ShowConnections;
use PhpMiniDatabase\Network\Protocol\Message\ShowStatus;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Transaction\IsolationLevel;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase
{
    private Codec $codec;

    protected function setUp(): void
    {
        $this->codec = new Codec();
    }

    private function roundTrip(Message $message): Message
    {
        return $this->codec->decode($this->codec->encode($message));
    }

    public function testHelloRoundTrips(): void
    {
        $message = new Hello(1, 'minidb-cli', '0.1.0', ['compression', 'pipelining']);

        $decoded = $this->roundTrip($message);

        self::assertInstanceOf(Hello::class, $decoded);
        self::assertSame(1, $decoded->protocolVersion);
        self::assertSame('minidb-cli', $decoded->clientName);
        self::assertSame('0.1.0', $decoded->clientVersion);
        self::assertSame(['compression', 'pipelining'], $decoded->capabilities);
    }

    public function testHelloAckRoundTrips(): void
    {
        $message = new HelloAck('0.1.0', 'challenge_response', "\x01\x02\x03nonce", []);

        $decoded = $this->roundTrip($message);

        self::assertInstanceOf(HelloAck::class, $decoded);
        self::assertSame('0.1.0', $decoded->serverVersion);
        self::assertSame('challenge_response', $decoded->authMethod);
        self::assertSame("\x01\x02\x03nonce", $decoded->nonce);
        self::assertSame([], $decoded->capabilities);
    }

    public function testAuthRoundTrips(): void
    {
        $decoded = $this->roundTrip(new Auth('alice', "\xDE\xAD\xBE\xEF"));

        self::assertInstanceOf(Auth::class, $decoded);
        self::assertSame('alice', $decoded->username);
        self::assertSame("\xDE\xAD\xBE\xEF", $decoded->response);
    }

    public function testAuthOkRoundTrips(): void
    {
        $decoded = $this->roundTrip(new AuthOk('sess-1', 1_700_000_000));

        self::assertInstanceOf(AuthOk::class, $decoded);
        self::assertSame('sess-1', $decoded->sessionId);
        self::assertSame(1_700_000_000, $decoded->serverTime);
    }

    public function testAuthFailRoundTrips(): void
    {
        $decoded = $this->roundTrip(new AuthFail('bad password'));

        self::assertInstanceOf(AuthFail::class, $decoded);
        self::assertSame('bad password', $decoded->reason);
    }

    public function testQueryRoundTripsWithParameters(): void
    {
        $message = new Query('SELECT * FROM users WHERE age > ? AND name = ?', [18, 'Ann']);

        $decoded = $this->roundTrip($message);

        self::assertInstanceOf(Query::class, $decoded);
        self::assertSame('SELECT * FROM users WHERE age > ? AND name = ?', $decoded->sql);
        self::assertSame([18, 'Ann'], $decoded->parameters);
    }

    public function testQueryWithNoParametersRoundTrips(): void
    {
        $decoded = $this->roundTrip(new Query('SELECT 1'));

        self::assertInstanceOf(Query::class, $decoded);
        self::assertSame([], $decoded->parameters);
    }

    public function testQueryResultRoundTrips(): void
    {
        $message = new QueryResultMessage(['id', 'name'], [[1, 'Ann'], [2, null]]);

        $decoded = $this->roundTrip($message);

        self::assertInstanceOf(QueryResultMessage::class, $decoded);
        self::assertSame(['id', 'name'], $decoded->columns);
        self::assertSame([[1, 'Ann'], [2, null]], $decoded->rows);
    }

    public function testAnEmptyQueryResultRoundTrips(): void
    {
        $decoded = $this->roundTrip(new QueryResultMessage([], []));

        self::assertInstanceOf(QueryResultMessage::class, $decoded);
        self::assertSame([], $decoded->columns);
        self::assertSame([], $decoded->rows);
    }

    public function testQueryErrorRoundTripsWithContext(): void
    {
        $message = new QueryError(
            ErrorCode::CONSTRAINT_VIOLATION,
            'Duplicate entry for key "uq_users_email"',
            ['table' => 'users', 'constraint' => 'uq_users_email', 'value' => 'alice@example.com'],
        );

        $decoded = $this->roundTrip($message);

        self::assertInstanceOf(QueryError::class, $decoded);
        self::assertSame(ErrorCode::CONSTRAINT_VIOLATION, $decoded->code);
        self::assertSame('Duplicate entry for key "uq_users_email"', $decoded->message);
        self::assertSame(
            ['table' => 'users', 'constraint' => 'uq_users_email', 'value' => 'alice@example.com'],
            $decoded->context,
        );
    }

    public function testPrepareAndPrepareOkRoundTrip(): void
    {
        $prepared = $this->roundTrip(new Prepare('SELECT * FROM users WHERE id = ?'));
        self::assertInstanceOf(Prepare::class, $prepared);
        self::assertSame('SELECT * FROM users WHERE id = ?', $prepared->sql);

        $ok = $this->roundTrip(new PrepareOk(42));
        self::assertInstanceOf(PrepareOk::class, $ok);
        self::assertSame(42, $ok->statementId);
    }

    public function testExecuteRoundTrips(): void
    {
        $decoded = $this->roundTrip(new Execute(42, [1, 'two', null]));

        self::assertInstanceOf(Execute::class, $decoded);
        self::assertSame(42, $decoded->statementId);
        self::assertSame([1, 'two', null], $decoded->parameters);
    }

    public function testCloseStatementRoundTrips(): void
    {
        $decoded = $this->roundTrip(new CloseStatement(7));

        self::assertInstanceOf(CloseStatement::class, $decoded);
        self::assertSame(7, $decoded->statementId);
    }

    public function testBeginWithNoIsolationLevelRoundTrips(): void
    {
        $decoded = $this->roundTrip(new Begin());

        self::assertInstanceOf(Begin::class, $decoded);
        self::assertNull($decoded->isolationLevel);
    }

    public function testBeginWithEachIsolationLevelRoundTrips(): void
    {
        foreach (IsolationLevel::cases() as $level) {
            $decoded = $this->roundTrip(new Begin($level));

            self::assertInstanceOf(Begin::class, $decoded);
            self::assertSame($level, $decoded->isolationLevel);
        }
    }

    public function testCommitAndRollbackRoundTrip(): void
    {
        self::assertInstanceOf(Commit::class, $this->roundTrip(new Commit()));
        self::assertInstanceOf(Rollback::class, $this->roundTrip(new Rollback()));
    }

    public function testSavepointRoundTrips(): void
    {
        $decoded = $this->roundTrip(new Savepoint('sp1'));

        self::assertInstanceOf(Savepoint::class, $decoded);
        self::assertSame('sp1', $decoded->name);
    }

    public function testPingPongGoodbyeCancelRoundTrip(): void
    {
        self::assertInstanceOf(Ping::class, $this->roundTrip(new Ping()));
        self::assertInstanceOf(Pong::class, $this->roundTrip(new Pong()));
        self::assertInstanceOf(Goodbye::class, $this->roundTrip(new Goodbye()));
        self::assertInstanceOf(Cancel::class, $this->roundTrip(new Cancel()));
    }

    public function testShowStatusAndShowConnectionsRoundTrip(): void
    {
        self::assertInstanceOf(ShowStatus::class, $this->roundTrip(new ShowStatus()));
        self::assertInstanceOf(ShowConnections::class, $this->roundTrip(new ShowConnections()));
    }

    public function testKillRoundTrips(): void
    {
        $decoded = $this->roundTrip(new Kill(99));

        self::assertInstanceOf(Kill::class, $decoded);
        self::assertSame(99, $decoded->connectionId);
    }

    public function testCopyInCarriesItsRawBytesUnchanged(): void
    {
        $decoded = $this->roundTrip(new CopyIn('raw,csv,bytes'));

        self::assertInstanceOf(CopyIn::class, $decoded);
        self::assertSame('raw,csv,bytes', $decoded->data);
    }

    public function testEncodePutsTheMessageTypeInTheFrame(): void
    {
        $frame = $this->codec->encode(new Ping());

        self::assertSame(MessageType::PING, $frame->type);
    }
}

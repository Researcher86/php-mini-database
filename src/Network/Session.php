<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use DateTimeImmutable;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Network\Auth\Authenticator;
use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Auth;
use PhpMiniDatabase\Network\Protocol\Message\AuthFail;
use PhpMiniDatabase\Network\Protocol\Message\AuthOk;
use PhpMiniDatabase\Network\Protocol\Message\Begin;
use PhpMiniDatabase\Network\Protocol\Message\CloseStatement;
use PhpMiniDatabase\Network\Protocol\Message\Commit;
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
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Protocol\Message\Rollback;
use PhpMiniDatabase\Network\Protocol\Message\Savepoint;
use PhpMiniDatabase\Network\Protocol\Message\ShowConnections;
use PhpMiniDatabase\Network\Protocol\Message\ShowStatus;
use PhpMiniDatabase\Network\Protocol\ResultEncoder;
use PhpMiniDatabase\Sql\Ast\BeginStatement;
use PhpMiniDatabase\Sql\Ast\CommitStatement;
use PhpMiniDatabase\Sql\Ast\RollbackStatement;
use PhpMiniDatabase\Sql\Ast\SavepointStatement;
use PhpMiniDatabase\Sql\Ast\Statement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Support\Clock;
use PhpMiniDatabase\Support\SystemClock;
use Throwable;

/**
 * One client connection: its socket, and everything it takes to turn
 * incoming bytes into `Execution\Executor::run()` calls and outgoing
 * bytes. `EventLoop` owns *when* `handleReadable()` runs (once its socket
 * is readable); this class owns everything about *what happens* once it
 * does.
 *
 * `$authenticator === null` is Milestone 12's "dev mode" (PLAN.md §11):
 * `HELLO` is answered immediately and every `Query` runs with no `Auth`
 * exchange at all. Given one, `HELLO` instead hands back a fresh nonce
 * (`HelloAck::$nonce`) and a `Query` before a matching `Auth` succeeds is
 * refused the same way an unsupported message is (see below) — `AUTH_FAIL`
 * does *not* close the connection, so a client that mistyped a password
 * can retry with a new `Auth` message on the same connection.
 *
 * `Begin`/`Commit`/`Rollback`/`Savepoint` (Milestone 15) are thin wrappers
 * around the same `Sql\Ast\*Statement` classes `Query`'s plain SQL text
 * already produced since Phase 8 — `handleBegin()` etc. build one by hand
 * and hand it to `Executor::execute()` directly, the same method every
 * other path here already calls, rather than duplicating `BEGIN`'s
 * behavior a second time.
 *
 * `close()`'s `Executor::inTransaction()` check is what makes a client's
 * disconnect mid-transaction safe: `Schema\Database` allows only one open
 * transaction system-wide (Phase 8's single-writer model), so a session
 * that opens one and then disconnects without `COMMIT`/`ROLLBACK` would
 * otherwise leave it open forever, locking out every other session's
 * `BEGIN` for good. `inTransaction()` answers *this* session's own
 * question — whether its own `Executor` is the one that opened whatever
 * transaction is currently open, not merely whether one is open at all —
 * so `close()` only rolls back a transaction this session actually owns,
 * never one left open by whichever other connection does.
 *
 * `Prepare`/`Execute`/`CloseStatement` (Milestone 14): `$preparedStatements`
 * caches `Sql\Ast\Statement`, not raw SQL — `PREPARE` parses once via
 * `Parser::parseOne()`, and every later `EXECUTE` skips straight to
 * `Executor::execute()`, which already accepts a pre-parsed `Statement`
 * plus a parameter list. This is *parse-once*, not *plan-once*: a `SELECT`
 * still goes through `Executor::plan()`/the optimizer fresh on every
 * `EXECUTE`, since nothing here caches a `LogicalPlan` across calls — a
 * smaller, honest scope than a real database's "prepared" usually implies,
 * but the actual protection PLAN.md §2.1 asks for either way, since a bound
 * parameter is always a `WireValue`-typed value carried alongside the
 * statement, never text spliced into it: nothing about `PREPARE`/`EXECUTE`
 * lets a parameter be interpreted as SQL syntax.
 *
 * There is no dedicated wire message for "PREPARE failed" (bad syntax, or
 * the statement limit below), the same way `EXECUTE` has none either —
 * both are reported through `QueryError`, exactly as `handleQuery()`
 * already does, rather than inventing a message PLAN.md's table does not
 * have. `CLOSE_STMT` has no reply at all (PLAN.md §5's table lists it
 * client-to-server only) and closing an id that is unknown or already
 * closed is not an error — a fire-and-forget release message being
 * idempotent is the ordinary case, not a special one.
 *
 * `ShowStatus`/`ShowConnections`/`Kill` (Milestone 18) each answer with a
 * `QueryResultMessage` too, the same as everything else here — one row of
 * counters from `$metrics` for `SHOW_STATUS`, one row per `$sessions->all()`
 * for `SHOW_CONNECTIONS`, and an empty one for a successful `KILL`
 * (matching the DDL/transaction-control convention: nothing to report).
 * `KILL` of an unknown connection id is a `QueryError`, the same shape
 * `EXECUTE` of an unknown statement id already uses. `$username` is
 * `null` until a successful `Auth` sets it (or forever, in dev mode,
 * where there is no login to know one from) — what `SHOW_CONNECTIONS`
 * shows for a connection this server cannot otherwise name.
 *
 * A response is written in one `fwrite()` call, no write buffering or
 * write-readiness registration — correct for the size of a response this
 * phase actually produces, and a named gap for the day a `QUERY_RESULT`
 * large enough not to fit in one non-blocking write exists.
 */
final class Session
{
    private readonly FrameReader $reader;

    private readonly Codec $codec;

    private readonly ResultEncoder $resultEncoder;

    private ?string $nonce = null;

    private bool $authenticated = false;

    private bool $closed = false;

    /** @var array<int, Statement> */
    private array $preparedStatements = [];

    private int $nextStatementId = 1;

    private ?string $username = null;

    private readonly DateTimeImmutable $connectedAt;

    /** @param resource $socket */
    public function __construct(
        private mixed $socket,
        public readonly int $id,
        private readonly Executor $executor,
        private readonly SessionManager $sessions,
        private readonly Metrics $metrics,
        private readonly Logger $logger = new Logger(),
        private readonly ?Authenticator $authenticator = null,
        private readonly int $maxPreparedStatements = 100,
        Clock $clock = new SystemClock(),
    ) {
        $this->reader = new FrameReader();
        $this->codec = new Codec();
        $this->resultEncoder = new ResultEncoder();
        $this->connectedAt = $clock->now();
    }

    /** @return resource */
    public function socket(): mixed
    {
        return $this->socket;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function connectedAt(): DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function handleReadable(): void
    {
        $chunk = @fread($this->socket, 65536);

        if ($chunk === false || $chunk === '') {
            $this->close();

            return;
        }

        $this->reader->feed($chunk);

        try {
            while (($frame = $this->reader->next()) !== null) {
                $this->handleFrame($frame);

                if ($this->closed) {
                    return;
                }
            }
        } catch (ProtocolException $e) {
            $this->logger->warning(sprintf('Session %d sent a malformed frame: %s', $this->id, $e->getMessage()));
            $this->close();
        }
    }

    private function handleFrame(Frame $frame): void
    {
        $message = $this->codec->decode($frame);

        match (true) {
            $message instanceof Hello => $this->handleHello(),
            $message instanceof Auth => $this->handleAuth($message),
            $message instanceof Query => $this->handleQuery($message),
            $message instanceof Prepare => $this->handlePrepare($message),
            $message instanceof Execute => $this->handleExecute($message),
            $message instanceof CloseStatement => $this->handleCloseStatement($message),
            $message instanceof Begin => $this->handleBegin($message),
            $message instanceof Commit => $this->handleCommit($message),
            $message instanceof Rollback => $this->handleRollback($message),
            $message instanceof Savepoint => $this->handleSavepoint($message),
            $message instanceof ShowStatus => $this->handleShowStatus($message),
            $message instanceof ShowConnections => $this->handleShowConnections($message),
            $message instanceof Kill => $this->handleKill($message),
            $message instanceof Ping => $this->send(new Pong()),
            $message instanceof Goodbye => $this->close(),
            default => $this->rejectUnsupported($message),
        };
    }

    private function handleHello(): void
    {
        if ($this->authenticator === null) {
            $this->send(new HelloAck(Server::VERSION, 'none', ''));
            $this->authenticated = true;

            return;
        }

        $this->nonce = $this->authenticator->nonce();
        $this->send(new HelloAck(Server::VERSION, 'challenge_response', $this->nonce));
    }

    private function handleAuth(Auth $auth): void
    {
        if ($this->authenticator === null || $this->nonce === null) {
            $this->rejectUnsupported($auth);

            return;
        }

        $failureReason = $this->authenticator->verify($auth->username, $this->nonce, $auth->response);

        if ($failureReason !== null) {
            // Stays open: a client that sent the wrong password gets to
            // retry with a fresh AUTH on this same connection, rather than
            // having to redo HELLO from scratch.
            $this->send(new AuthFail($failureReason));

            return;
        }

        $this->authenticated = true;
        $this->username = $auth->username;
        $this->send(new AuthOk((string) $this->id, time()));
    }

    private function handleQuery(Query $query): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($query);

            return;
        }

        $this->runAndRespond(fn () => $this->executor->run($query->sql, $query->parameters));
    }

    private function handlePrepare(Prepare $prepare): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($prepare);

            return;
        }

        if (count($this->preparedStatements) >= $this->maxPreparedStatements) {
            $this->send($this->resultEncoder->encodeError(new ExecutionException(sprintf(
                'Cannot prepare another statement: this connection already has %d open (the limit).',
                $this->maxPreparedStatements,
            ))));

            return;
        }

        try {
            $statement = Parser::parseOne($prepare->sql);
        } catch (Throwable $e) {
            $this->send($this->resultEncoder->encodeError($e));

            return;
        }

        $statementId = $this->nextStatementId++;
        $this->preparedStatements[$statementId] = $statement;
        $this->send(new PrepareOk($statementId));
    }

    private function handleExecute(Execute $execute): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($execute);

            return;
        }

        $statement = $this->preparedStatements[$execute->statementId] ?? null;

        if ($statement === null) {
            $this->send($this->resultEncoder->encodeError(new ExecutionException(sprintf(
                'No prepared statement %d on this connection.',
                $execute->statementId,
            ))));

            return;
        }

        $this->runAndRespond(fn () => $this->executor->execute($statement, $execute->parameters));
    }

    private function handleCloseStatement(CloseStatement $close): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($close);

            return;
        }

        unset($this->preparedStatements[$close->statementId]);
    }

    private function handleBegin(Begin $begin): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($begin);

            return;
        }

        $this->runAndRespond(fn () => $this->executor->execute(new BeginStatement($begin->isolationLevel)));
    }

    private function handleCommit(Commit $commit): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($commit);

            return;
        }

        $this->runAndRespond(fn () => $this->executor->execute(new CommitStatement()));
    }

    private function handleRollback(Rollback $rollback): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($rollback);

            return;
        }

        $this->runAndRespond(fn () => $this->executor->execute(new RollbackStatement()));
    }

    private function handleSavepoint(Savepoint $savepoint): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($savepoint);

            return;
        }

        $this->runAndRespond(fn () => $this->executor->execute(new SavepointStatement($savepoint->name)));
    }

    private function handleShowStatus(ShowStatus $showStatus): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($showStatus);

            return;
        }

        $this->send(new QueryResultMessage(
            ['uptime_seconds', 'active_connections', 'total_connections', 'total_queries', 'total_errors', 'queries_per_second'],
            [[
                (int) round($this->metrics->uptimeSeconds()),
                $this->sessions->count(),
                $this->metrics->totalConnections(),
                $this->metrics->totalQueries(),
                $this->metrics->totalErrors(),
                round($this->metrics->queriesPerSecond(), 3),
            ]],
        ));
    }

    private function handleShowConnections(ShowConnections $showConnections): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($showConnections);

            return;
        }

        $rows = [];

        foreach ($this->sessions->all() as $session) {
            $rows[] = [$session->id, $session->username(), $session->connectedAt(), $session->isAuthenticated()];
        }

        $this->send(new QueryResultMessage(['id', 'username', 'connected_at', 'authenticated'], $rows));
    }

    private function handleKill(Kill $kill): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($kill);

            return;
        }

        $target = $this->sessions->find($kill->connectionId);

        if ($target === null) {
            $this->send($this->resultEncoder->encodeError(new ExecutionException(sprintf(
                'No connection %d.',
                $kill->connectionId,
            ))));

            return;
        }

        $target->close();
        $this->send(new QueryResultMessage([], []));
    }

    /** @param callable(): (QueryResult|int|null) $run */
    private function runAndRespond(callable $run): void
    {
        $this->metrics->recordQuery();

        try {
            $result = $run();
            $this->send($this->resultEncoder->encode($result));
        } catch (Throwable $e) {
            $this->metrics->recordError();
            $this->send($this->resultEncoder->encodeError($e));
        }
    }

    private function rejectUnsupported(Message $message): void
    {
        $this->logger->warning(sprintf(
            'Session %d sent %s, which is not supported yet; closing the connection.',
            $this->id,
            $message::class,
        ));
        $this->close();
    }

    private function send(Message $message): void
    {
        $bytes = $this->codec->encode($message)->toBytes();
        $written = @fwrite($this->socket, $bytes);

        if ($written !== strlen($bytes)) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->executor->inTransaction()) {
            try {
                $this->executor->execute(new RollbackStatement());
            } catch (Throwable $e) {
                $this->logger->warning(sprintf(
                    'Session %d disconnected mid-transaction and its automatic ROLLBACK failed: %s',
                    $this->id,
                    $e->getMessage(),
                ));
            }
        }

        $this->closed = true;
        fclose($this->socket);
    }
}

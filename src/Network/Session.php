<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Network\Auth\Authenticator;
use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Auth;
use PhpMiniDatabase\Network\Protocol\Message\AuthFail;
use PhpMiniDatabase\Network\Protocol\Message\AuthOk;
use PhpMiniDatabase\Network\Protocol\Message\CloseStatement;
use PhpMiniDatabase\Network\Protocol\Message\Execute;
use PhpMiniDatabase\Network\Protocol\Message\Goodbye;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Ping;
use PhpMiniDatabase\Network\Protocol\Message\Pong;
use PhpMiniDatabase\Network\Protocol\Message\Prepare;
use PhpMiniDatabase\Network\Protocol\Message\PrepareOk;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\ResultEncoder;
use PhpMiniDatabase\Sql\Ast\Statement;
use PhpMiniDatabase\Sql\Parser;
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
 * `Begin`/`Commit`/`Rollback`/`Savepoint` — every `Message` type this phase
 * still does not handle — close the connection rather than being silently
 * ignored: `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` already work today, but
 * only as plain SQL text through `Query` (`Executor` has parsed and run
 * them since Phase 8) — the *typed* wire messages for them are
 * Milestone 15's job, once a session has more reason to prefer them over
 * SQL text.
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

    /** @param resource $socket */
    public function __construct(
        private mixed $socket,
        public readonly int $id,
        private readonly Executor $executor,
        private readonly Logger $logger = new Logger(),
        private readonly ?Authenticator $authenticator = null,
        private readonly int $maxPreparedStatements = 100,
    ) {
        $this->reader = new FrameReader();
        $this->codec = new Codec();
        $this->resultEncoder = new ResultEncoder();
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
        $this->send(new AuthOk((string) $this->id, time()));
    }

    private function handleQuery(Query $query): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($query);

            return;
        }

        try {
            $result = $this->executor->run($query->sql, $query->parameters);
            $this->send($this->resultEncoder->encode($result));
        } catch (Throwable $e) {
            $this->send($this->resultEncoder->encodeError($e));
        }
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

        try {
            $result = $this->executor->execute($statement, $execute->parameters);
            $this->send($this->resultEncoder->encode($result));
        } catch (Throwable $e) {
            $this->send($this->resultEncoder->encodeError($e));
        }
    }

    private function handleCloseStatement(CloseStatement $close): void
    {
        if (!$this->authenticated) {
            $this->rejectUnsupported($close);

            return;
        }

        unset($this->preparedStatements[$close->statementId]);
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

        $this->closed = true;
        fclose($this->socket);
    }
}

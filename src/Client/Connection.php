<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Client;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Auth\PasswordHash;
use PhpMiniDatabase\Network\Auth\ScramChallenge;
use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
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
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Protocol\Message\Rollback;
use PhpMiniDatabase\Network\Protocol\Message\Savepoint;
use PhpMiniDatabase\Network\Protocol\Message\ShowConnections;
use PhpMiniDatabase\Network\Protocol\Message\ShowStatus;
use PhpMiniDatabase\Transaction\IsolationLevel;
use Throwable;

/**
 * One TCP connection to a `Network\Server`, from the other side — PLAN.md
 * §8.2's `Connection::connect($config)`. Where `Network\Session` turns
 * incoming bytes into `Executor` calls, this turns outgoing method calls
 * into bytes: every public method here is one request, sent and then
 * blocked on for exactly one reply, matching how a caller actually wants
 * to use it (`$conn->query(...)` is one call, not a send half and a
 * receive half to remember to pair up).
 *
 * The socket itself is non-blocking (`stream_set_blocking(..., false)`,
 * matching `Network\Acceptor`'s own choice) and every wait for
 * readability or writability goes through `waitForStream()`'s
 * `stream_select()` — the same primitive `Network\EventLoop` already
 * uses, just called once per request here instead of once per `tick()` —
 * rather than a blocking socket plus `stream_set_timeout()`, because PHP's
 * stream timeout is one shared setting for both directions: it could not
 * give `$connectTimeoutSeconds`, `$readTimeoutSeconds` and
 * `$writeTimeoutSeconds` genuinely independent budgets the way three
 * separate `ClientConfig` fields promise. See DECISIONS.md.
 *
 * `reconnect()` replaces the underlying socket in place, keeping this same
 * `Connection` object's identity — what `ConnectionPool::acquire()` relies
 * on to hand back a working connection instead of a dead one without
 * every caller needing a fresh reference.
 */
final class Connection
{
    public const CLIENT_NAME = 'php-mini-database';

    public const CLIENT_VERSION = '0.1.0';

    /** @var resource */
    private mixed $socket;

    private FrameReader $reader;

    private readonly Codec $codec;

    private bool $closed = false;

    private function __construct(
        mixed $socket,
        private readonly ClientConfig $config,
    ) {
        $this->socket = $socket;
        $this->reader = new FrameReader();
        $this->codec = new Codec();
    }

    public static function connect(ClientConfig $config): self
    {
        $connection = new self(self::openSocket($config), $config);
        $connection->handshakeOrClose();

        return $connection;
    }

    /**
     * Closes the current socket (if still open) and opens, and
     * re-authenticates, a brand new one in its place — for a caller (or
     * `ConnectionPool`) that has noticed this connection is dead and wants
     * a working one back under the same reference, rather than having to
     * discard this object and track down every place that held it.
     */
    public function reconnect(): void
    {
        if (!$this->closed) {
            @fclose($this->socket);
        }

        $this->socket = self::openSocket($this->config);
        $this->reader = new FrameReader();
        // Flipped before handshake(), not after: send()/receive() (which
        // handshake() itself calls) refuse to run at all once $closed is
        // true, via requireOpen() - the same guard connect()'s own,
        // freshly-constructed Connection satisfies by starting out false.
        $this->closed = false;
        $this->handshakeOrClose();
    }

    /** @param list<mixed> $parameters */
    public function query(string $sql, array $parameters = []): ResultSet
    {
        return $this->request(new Query($sql, $parameters));
    }

    /**
     * `query()`, for a statement with nothing to fetch — an `INSERT`/
     * `UPDATE`/`DELETE`'s affected-row count (via `ResultSet::affectedRows()`),
     * or `null` for DDL. Not a different wire request: every `Query`
     * answers with the same `QUERY_RESULT` regardless of statement kind
     * (see `Network\Protocol\ResultEncoder`'s docblock) — this is purely a
     * more convenient return type for a caller who does not want rows.
     *
     * @param list<mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): ?int
    {
        return $this->query($sql, $parameters)->affectedRows();
    }

    public function prepare(string $sql): Statement
    {
        $this->send(new Prepare($sql));

        return new Statement($this, $this->receiveReply(PrepareOk::class, 'PREPARE_OK')->statementId);
    }

    /** @param list<mixed> $parameters */
    public function executePrepared(int $statementId, array $parameters = []): ResultSet
    {
        return $this->request(new Execute($statementId, $parameters));
    }

    public function closeStatement(int $statementId): void
    {
        $this->send(new CloseStatement($statementId));
    }

    public function beginTransaction(?IsolationLevel $isolationLevel = null): void
    {
        $this->request(new Begin($isolationLevel));
    }

    public function commit(): void
    {
        $this->request(new Commit());
    }

    public function rollback(): void
    {
        $this->request(new Rollback());
    }

    public function savepoint(string $name): void
    {
        $this->request(new Savepoint($name));
    }

    /** PLAN.md §10.4's `SHOW STATUS` — one row of server-wide counters (see `Network\Session::handleShowStatus()`). */
    public function showStatus(): ResultSet
    {
        return $this->request(new ShowStatus());
    }

    /** PLAN.md §10.4's `SHOW CONNECTIONS` — one row per currently-open session. */
    public function showConnections(): ResultSet
    {
        return $this->request(new ShowConnections());
    }

    /** PLAN.md §10.4's `KILL <id>` — closes another session by its `Session::$id`. */
    public function kill(int $connectionId): void
    {
        $this->request(new Kill($connectionId));
    }

    /** A round trip through `PING`/`PONG` — whether this connection is still good for use. */
    public function isAlive(): bool
    {
        if ($this->closed) {
            return false;
        }

        try {
            $this->send(new Ping());

            return $this->receive() instanceof Pong;
        } catch (Throwable) {
            return false;
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->send(new Goodbye());
        } catch (Throwable) {
            // Best-effort - the socket may already be broken, which is
            // exactly the case this call exists to close out cleanly.
        }

        $this->closed = true;
        @fclose($this->socket);
    }

    /**
     * `stream_select()` throws a `TypeError` given an already-closed
     * resource rather than failing gracefully the way it does for a
     * *broken* one — a real difference this project's other non-blocking
     * socket code (`Network\Acceptor`/`Session`) never has to guard
     * against, since nothing there keeps a `Session` object alive past
     * closing its socket the way a caller may keep a `Connection` alive
     * past `close()`. Checked at both `send()` and `receive()`, the two
     * entry points every public method here funnels through, so using a
     * closed connection is always a clean `ClientException`.
     */
    private function requireOpen(): void
    {
        if ($this->closed) {
            throw new ClientException('This connection is closed.');
        }
    }

    private function handshake(): void
    {
        $this->send(new Hello(Frame::CURRENT_VERSION, self::CLIENT_NAME, self::CLIENT_VERSION));
        $ack = $this->receive();

        if (!$ack instanceof HelloAck) {
            throw new ClientException(sprintf('Expected HELLO_ACK, got %s.', $ack::class));
        }

        if ($ack->authMethod === 'none') {
            return;
        }

        $salt = PasswordHash::saltFor($this->config->user);
        $hash = PasswordHash::derive($this->config->password, $salt);
        $response = ScramChallenge::respond($hash, $ack->nonce);

        $this->send(new Auth($this->config->user, $response));
        $reply = $this->receive();

        if ($reply instanceof AuthFail) {
            throw new ClientException(sprintf('Authentication failed: %s', $reply->reason), ErrorCode::AUTH_FAILED);
        }

        if (!$reply instanceof AuthOk) {
            throw new ClientException(sprintf('Expected AUTH_OK, got %s.', $reply::class));
        }
    }

    /** `handshake()`, closing the fresh socket again if it fails. */
    private function handshakeOrClose(): void
    {
        try {
            $this->handshake();
        } catch (Throwable $e) {
            $this->closed = true;
            @fclose($this->socket);

            throw $e;
        }
    }

    /**
     * One request answered by a `QUERY_RESULT`. That includes the replies
     * that carry nothing worth reading — `BEGIN`, `COMMIT`, `ROLLBACK`,
     * `SAVEPOINT`, `KILL`: on the wire each *is* a result (`ResultEncoder`
     * answers every one of them with an empty `QUERY_RESULT`), so it is
     * validated exactly like one, and its caller simply discards it.
     */
    private function request(Message $message): ResultSet
    {
        $this->send($message);
        $reply = $this->receiveReply(QueryResultMessage::class, 'QUERY_RESULT');

        return new ResultSet($reply->columns, $reply->rows);
    }

    /**
     * The next reply, which must be a $expected — a `QUERY_ERROR` instead
     * becomes the `ClientException` it describes.
     *
     * @template T of Message
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function receiveReply(string $expected, string $wireName): Message
    {
        $reply = $this->receive();

        if ($reply instanceof QueryError) {
            throw ClientException::fromQueryError($reply);
        }

        if (!$reply instanceof $expected) {
            throw new ClientException(sprintf('Expected %s, got %s.', $wireName, $reply::class));
        }

        return $reply;
    }

    private function send(Message $message): void
    {
        $this->requireOpen();
        $this->writeAll($this->codec->encode($message)->toBytes());
    }

    private function receive(): Message
    {
        $this->requireOpen();
        $frame = $this->readFrame();

        try {
            return $this->codec->decode($frame);
        } catch (ProtocolException $e) {
            throw new ClientException('The server sent a malformed message.', previous: $e);
        }
    }

    private function readFrame(): Frame
    {
        $deadline = microtime(true) + $this->config->readTimeoutSeconds;

        while (true) {
            try {
                $frame = $this->reader->next();
            } catch (ProtocolException $e) {
                throw new ClientException('The server sent a malformed frame.', previous: $e);
            }

            if ($frame !== null) {
                return $frame;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new ClientException(sprintf(
                    'Timed out after %.1fs waiting for a reply from the server.',
                    $this->config->readTimeoutSeconds,
                ));
            }

            $this->waitForStream($remaining, forWrite: false);

            $chunk = @fread($this->socket, 65536);

            if ($chunk === false || $chunk === '') {
                throw new ClientException('The server closed the connection.');
            }

            $this->reader->feed($chunk);
        }
    }

    private function writeAll(string $bytes): void
    {
        $deadline = microtime(true) + $this->config->writeTimeoutSeconds;

        while ($bytes !== '') {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new ClientException(sprintf(
                    'Timed out after %.1fs sending a message to the server.',
                    $this->config->writeTimeoutSeconds,
                ));
            }

            $this->waitForStream($remaining, forWrite: true);

            $written = @fwrite($this->socket, $bytes);

            if ($written === false || $written === 0) {
                throw new ClientException('The connection to the server was lost while sending a message.');
            }

            $bytes = substr($bytes, $written);
        }
    }

    private function waitForStream(float $timeoutSeconds, bool $forWrite): void
    {
        $seconds = (int) floor($timeoutSeconds);
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
        $read = $forWrite ? null : [$this->socket];
        $write = $forWrite ? [$this->socket] : null;
        $except = null;

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false) {
            throw new ClientException('The connection to the server failed.');
        }

        if ($ready === 0) {
            throw new ClientException(sprintf('Timed out after %.1fs waiting for the server.', $timeoutSeconds));
        }
    }

    /** @return resource */
    private static function openSocket(ClientConfig $config): mixed
    {
        $address = sprintf('tcp://%s:%d', $config->host, $config->port);
        $socket = @stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $config->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new ClientException(sprintf(
                'Could not connect to %s: %s',
                $address,
                $errorMessage !== '' ? $errorMessage : 'unknown error',
            ));
        }

        stream_set_blocking($socket, false);

        return $socket;
    }
}

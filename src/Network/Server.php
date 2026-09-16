<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use LogicException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Network\Auth\Authenticator;
use PhpMiniDatabase\Network\Auth\UserStore;
use PhpMiniDatabase\Schema\Database;
use Throwable;

/**
 * Wires `Acceptor`, `EventLoop` and `SessionManager` to one `Schema\Database`:
 * a new connection becomes a `Session` sharing that `Database` (and, through
 * it, PLAN.md §3.4's single writer — `Transaction\TransactionManager` refuses
 * a second `BEGIN` while one session already holds it, exactly as it would
 * refuse a second `Execution\Executor` in one process; see DECISIONS.md,
 * Phase 8), registered with the loop so its own socket becoming readable
 * calls `Session::handleReadable()`.
 *
 * `start()` binds and begins accepting; `tick()`/`run()` are `EventLoop`'s
 * own methods, exposed here so a caller — a test, or `bin/minidb-server` —
 * never has to reach past `Server` into its `EventLoop` directly. `run()`
 * additionally installs `SIGTERM`/`SIGINT` handlers first, so a signal
 * received mid-`run()` sets the same stop flag `EventLoop::stop()` would; a
 * test driving `tick()` by hand never touches process-wide signal state at
 * all, since it never calls `run()`.
 *
 * `ServerConfig::$authEnabled` decides whether every `Session` gets a real
 * `Network\Auth\Authenticator` (reading `ServerConfig::resolvedUserStorePath()`)
 * or `null` — Milestone 12's "dev mode", still the default (Phase 13).
 */
final class Server
{
    public const VERSION = '0.1.0';

    private readonly Database $database;

    private readonly SessionManager $sessions;

    private readonly EventLoop $loop;

    private readonly ?Authenticator $authenticator;

    private ?Acceptor $acceptor = null;

    private int $nextSessionId = 1;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly Logger $logger = new Logger(),
    ) {
        $this->database = Database::open($config->dataDirectory);
        $this->sessions = new SessionManager($config->maxConnections);
        $this->loop = new EventLoop();
        $this->authenticator = $config->authEnabled
            ? new Authenticator(new UserStore($config->resolvedUserStorePath()))
            : null;
    }

    /** Binds the listening socket and starts accepting connections. Does not block. */
    public function start(): void
    {
        $this->acceptor = new Acceptor($this->config);
        $this->loop->onReadable($this->acceptor->socket(), function (): void {
            $this->acceptConnection();
        });

        $this->logger->info(sprintf('Listening on %s.', $this->acceptor->localAddress()));
    }

    /** The address actually bound — resolves `ServerConfig::$port`'s `0` to the OS-assigned port. */
    public function localAddress(): string
    {
        return $this->requireAcceptor()->localAddress();
    }

    public function sessionCount(): int
    {
        return $this->sessions->count();
    }

    /** One pass of the event loop — what a test drives by hand instead of calling `run()`. */
    public function tick(float $timeoutSeconds = 1.0): void
    {
        $this->loop->tick($timeoutSeconds);
    }

    /** Blocks, serving connections, until a `SIGTERM`/`SIGINT` (or `EventLoop::stop()`) asks it to stop. */
    public function run(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->logger->info('Received SIGTERM, shutting down.');
            $this->loop->stop();
        });
        pcntl_signal(SIGINT, function (): void {
            $this->logger->info('Received SIGINT, shutting down.');
            $this->loop->stop();
        });

        $this->loop->run();
        $this->shutdown();
    }

    /**
     * Closes every open session and the listening socket, and closes the
     * `Database`. Safe to call directly (a test's `tearDown()`, above all)
     * without ever having called `run()`.
     */
    public function shutdown(): void
    {
        $this->sessions->closeAll();
        $this->acceptor?->close();
        $this->database->close();
    }

    private function acceptConnection(): void
    {
        $connection = $this->requireAcceptor()->accept();

        if ($connection === null) {
            return;
        }

        if (!$this->sessions->hasCapacity()) {
            $this->logger->warning('Connection refused: max_connections reached.');
            fclose($connection);

            return;
        }

        $session = new Session($connection, $this->nextSessionId++, new Executor($this->database), $this->logger, $this->authenticator);
        $this->sessions->add($session);

        $this->loop->onReadable($connection, function () use ($session): void {
            $this->serviceSession($session);
        });

        $this->logger->info(sprintf('Session %d connected (%d active).', $session->id, $this->sessions->count()));
    }

    /**
     * `Session::handleReadable()` wrapped in its own `catch` — one
     * session's bug must never take the whole server down with it, since
     * every other session's socket is watched by this same process.
     */
    private function serviceSession(Session $session): void
    {
        try {
            $session->handleReadable();
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Session %d failed unexpectedly: %s', $session->id, $e->getMessage()));
            $session->close();
        }

        if ($session->isClosed()) {
            $this->loop->removeReadable($session->socket());
            $this->sessions->remove($session);
            $this->logger->info(sprintf('Session %d disconnected (%d active).', $session->id, $this->sessions->count()));
        }
    }

    private function requireAcceptor(): Acceptor
    {
        return $this->acceptor ?? throw new LogicException('Server::start() has not been called yet.');
    }
}

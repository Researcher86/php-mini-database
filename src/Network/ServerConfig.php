<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use PhpMiniDatabase\Infrastructure\Path;

/**
 * A server's settings — PLAN.md §7.1's `config/server.php` shape, as a
 * typed object instead of a bare array.
 *
 * `$idleTimeoutSeconds`/`$queryTimeoutSeconds` are recorded but not yet
 * enforced: closing a connection that has gone idle, or a query that has
 * run too long, needs `Session`/`EventLoop` to track elapsed time against
 * every open socket, which this phase's tick-driven loop does not do yet —
 * a named gap, the same shape as `Transaction\LockManager` existing before
 * anything could really contend for a lock (Phase 8). `$maxConnections`
 * and `$backlog` *are* enforced, by `SessionManager` and `Acceptor`
 * respectively.
 *
 * `$authEnabled` defaults to `false` — Milestone 12's "dev mode" stays the
 * default so every test and example written against it keeps working
 * unchanged; a caller opts into real authentication (Milestone 13)
 * explicitly. `$userStorePath` defaults to `users.json` inside
 * `$dataDirectory`, matching PLAN.md §6.1's layout, when left `null`.
 */
final readonly class ServerConfig
{
    public function __construct(
        public string $dataDirectory,
        public string $host = '127.0.0.1',
        public int $port = 5433,
        public int $maxConnections = 100,
        public int $backlog = 128,
        public int $idleTimeoutSeconds = 300,
        public int $queryTimeoutSeconds = 60,
        public bool $authEnabled = false,
        public ?string $userStorePath = null,
    ) {
    }

    public function resolvedUserStorePath(): string
    {
        return $this->userStorePath ?? Path::join($this->dataDirectory, 'users.json');
    }
}

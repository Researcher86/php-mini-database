<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

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
    ) {
    }
}

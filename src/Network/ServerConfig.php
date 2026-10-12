<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use PhpMiniDatabase\Infrastructure\Path;

/**
 * A server's settings — PLAN.md §7.1's `config/server.php` shape, as a
 * typed object instead of a bare array.
 *
 * `$idleTimeoutSeconds` is enforced by `Server`: a connection whose
 * client has sent nothing for longer than this is closed (`0` or less
 * disables the check). `$queryTimeoutSeconds` is recorded but *not*
 * enforced, and is not expected to be in this design: a statement runs to
 * completion inside one synchronous `Executor` call on the server's only
 * thread, so nothing is left running to notice a deadline until the
 * statement has already finished — and interrupting it from a signal
 * handler would abandon a storage write halfway. See DECISIONS.md.
 * `$maxConnections` and `$backlog` are enforced too, by `SessionManager`
 * and `Acceptor` respectively.
 *
 * `$authEnabled` defaults to `false` — Milestone 12's "dev mode" stays the
 * default so every test and example written against it keeps working
 * unchanged; a caller opts into real authentication (Milestone 13)
 * explicitly. `$userStorePath` defaults to `users.json` inside
 * `$dataDirectory`, matching PLAN.md §6.1's layout, when left `null`.
 *
 * `$maxPreparedStatements` matches PLAN.md §7.1's `max_prepared_statements`
 * example (`100`) — a per-`Session` limit, not a server-wide one: each
 * connection gets its own budget, since prepared statements live in
 * `Session`, not in anything shared across connections.
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
        public int $maxPreparedStatements = 100,
    ) {
    }

    public function resolvedUserStorePath(): string
    {
        return $this->userStorePath ?? Path::join($this->dataDirectory, 'users.json');
    }
}

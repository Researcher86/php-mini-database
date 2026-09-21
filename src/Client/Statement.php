<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Client;

use Throwable;

/**
 * A `PREPARE`d statement's handle — PLAN.md §8.2's `$conn->prepare($sql)`.
 * Holds nothing but the id `PrepareOk` handed back and the `Connection` to
 * run it against; `execute()`/`close()` are thin wrappers around
 * `Connection::executePrepared()`/`closeStatement()`, the same way
 * `Network\Session`'s own `handleExecute()`/`handleCloseStatement()` are
 * thin wrappers server-side.
 *
 * `__destruct()` closes the statement if the caller never did, best-effort
 * — a `CLOSE_STMT` that never reaches the server (the connection is
 * already gone) is not a problem worth surfacing from a destructor, which
 * PHP would otherwise turn into an unhandled-exception fatal at shutdown.
 */
final class Statement
{
    private bool $closed = false;

    public function __construct(
        private readonly Connection $connection,
        public readonly int $id,
    ) {
    }

    /** @param list<mixed> $parameters */
    public function execute(array $parameters = []): ResultSet
    {
        return $this->connection->executePrepared($this->id, $parameters);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->connection->closeStatement($this->id);
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->close();
        } catch (Throwable) {
            // Best-effort, see this class's own docblock.
        }
    }
}

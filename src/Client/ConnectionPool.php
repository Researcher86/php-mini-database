<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Client;

/**
 * A small, fixed-size pool of `Connection`s — PLAN.md §8.3's `$pool->acquire()`/
 * `$pool->release()`. Nothing here waits for a connection to free up: a
 * `Server` this project builds is single-process and single-writer for
 * transactions regardless (Phase 8), so a pool holding a caller until one
 * frees up would only be trading one kind of waiting for another; `acquire()`
 * past `$maxConnections` throws instead, so a caller finds out immediately
 * rather than blocking on a queue this class does not implement.
 *
 * "Reconnect on failure" (PLAN.md §2.1) happens here, not silently inside
 * `Connection` itself: `acquire()` checks a reused connection with
 * `isAlive()` (a real `PING`/`PONG` round trip, not just "was it closed
 * locally") and calls `Connection::reconnect()` on it before handing it
 * back if not. A connection is never reconnected *mid-request* — only
 * between one caller's `release()` and the next caller's `acquire()` —
 * since silently retrying whatever the previous caller was doing could
 * replay a write that already reached the server once.
 */
final class ConnectionPool
{
    /** @var list<Connection> */
    private array $idle = [];

    private int $total = 0;

    public function __construct(
        private readonly ClientConfig $config,
        private readonly int $maxConnections = 10,
    ) {
    }

    public function acquire(): Connection
    {
        if ($this->idle !== []) {
            $connection = array_pop($this->idle);

            if (!$connection->isAlive()) {
                $connection->reconnect();
            }

            return $connection;
        }

        if ($this->total >= $this->maxConnections) {
            throw new ClientException(sprintf(
                'Connection pool exhausted: all %d connection(s) are already in use.',
                $this->maxConnections,
            ));
        }

        $connection = Connection::connect($this->config);
        $this->total++;

        return $connection;
    }

    /**
     * Returns a connection to the pool for reuse, whether or not it is
     * still good — `acquire()` is what checks that, once, right before
     * handing a reused connection back out, rather than every `release()`
     * paying for a round trip nothing may ever need.
     */
    public function release(Connection $connection): void
    {
        $this->idle[] = $connection;
    }

    public function close(): void
    {
        foreach ($this->idle as $connection) {
            $connection->close();
        }

        $this->idle = [];
        $this->total = 0;
    }
}

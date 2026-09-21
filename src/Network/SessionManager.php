<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

/**
 * Every currently-open `Session`, keyed by its id — what `Acceptor`'s
 * `$maxConnections` limit is checked against, and what `SHOW_CONNECTIONS`/
 * `KILL` (Milestone 18) read and act on through `all()`/`find()`. Nothing
 * here decides *when* a session is added or removed; `Server` does,
 * around the connection lifecycle it already owns.
 */
final class SessionManager
{
    /** @var array<int, Session> */
    private array $sessions = [];

    public function __construct(
        private int $maxConnections,
    ) {
    }

    public function count(): int
    {
        return count($this->sessions);
    }

    public function hasCapacity(): bool
    {
        return count($this->sessions) < $this->maxConnections;
    }

    public function maxConnections(): int
    {
        return $this->maxConnections;
    }

    /**
     * `SIGHUP`'s one reloadable setting (`bin/minidb-server reload`) — see
     * DECISIONS.md for why this is the only thing a reload currently
     * changes.
     */
    public function setMaxConnections(int $maxConnections): void
    {
        $this->maxConnections = $maxConnections;
    }

    public function add(Session $session): void
    {
        $this->sessions[$session->id] = $session;
    }

    public function remove(Session $session): void
    {
        unset($this->sessions[$session->id]);
    }

    /** @return list<Session> */
    public function all(): array
    {
        return array_values($this->sessions);
    }

    public function find(int $id): ?Session
    {
        return $this->sessions[$id] ?? null;
    }

    public function closeAll(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }

        $this->sessions = [];
    }
}

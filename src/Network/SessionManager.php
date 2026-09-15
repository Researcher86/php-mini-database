<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

/**
 * Every currently-open `Session`, keyed by its id — what `Acceptor`'s
 * `$maxConnections` limit is checked against, and (Milestone 18) what
 * `SHOW_CONNECTIONS`/`KILL` will eventually read and act on. Nothing here
 * decides *when* a session is added or removed; `Server` does, around the
 * connection lifecycle it already owns.
 */
final class SessionManager
{
    /** @var array<int, Session> */
    private array $sessions = [];

    public function __construct(
        private readonly int $maxConnections,
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

    public function closeAll(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }

        $this->sessions = [];
    }
}

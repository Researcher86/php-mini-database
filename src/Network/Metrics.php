<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use PhpMiniDatabase\Support\Clock;
use PhpMiniDatabase\Support\SystemClock;

/**
 * PLAN.md §2.1's "Metrics: connections, queries/sec, errors" — one
 * instance shared across every `Session` (owned by `Server`, injected the
 * same way `SessionManager` already is), since a per-connection count
 * would defeat the point of a server-wide status report.
 *
 * Cumulative counters only, kept in memory for the process's life — the
 * same scope `Network\Auth\LoginThrottle` and `Transaction\LockManager`
 * already settled on for the same reason (see `LoginThrottle`'s own
 * docblock): nothing here needs to survive a restart, and there is no
 * shared state between processes to keep it in even if it did.
 * `queriesPerSecond()` is a plain average since `$startedAt`, not a
 * sliding window — simple, and honest about not being a live rate.
 */
final class Metrics
{
    private int $totalConnections = 0;

    private int $totalQueries = 0;

    private int $totalErrors = 0;

    private readonly float $startedAt;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->startedAt = (float) $this->clock->now()->format('U.u');
    }

    public function recordConnection(): void
    {
        $this->totalConnections++;
    }

    public function recordQuery(): void
    {
        $this->totalQueries++;
    }

    public function recordError(): void
    {
        $this->totalErrors++;
    }

    public function totalConnections(): int
    {
        return $this->totalConnections;
    }

    public function totalQueries(): int
    {
        return $this->totalQueries;
    }

    public function totalErrors(): int
    {
        return $this->totalErrors;
    }

    public function uptimeSeconds(): float
    {
        return max(0.0, (float) $this->clock->now()->format('U.u') - $this->startedAt);
    }

    public function queriesPerSecond(): float
    {
        $uptime = $this->uptimeSeconds();

        return $uptime > 0.0 ? $this->totalQueries / $uptime : 0.0;
    }
}

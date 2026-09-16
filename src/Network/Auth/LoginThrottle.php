<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Auth;

use PhpMiniDatabase\Support\Clock;
use PhpMiniDatabase\Support\SystemClock;

/**
 * PLAN.md §13.3/§15's "rate limiting + backoff" for auth attempts, kept
 * per username, in memory, for the life of the server process — the same
 * scope `Transaction\LockManager` (Phase 8) settled on for the same
 * reason: this project has no shared state between processes to keep it
 * in, and nothing here needs to survive a restart.
 *
 * The first `$maxAttempts` failures cost nothing; every one after that
 * doubles the lockout — `$baseDelaySeconds`, then twice that, then four
 * times, capped at `$maxDelaySeconds` — so a script guessing passwords
 * pays more for every wrong guess instead of being stopped outright after
 * one fixed count, which a legitimate user who mistypes their password a
 * few times in a row would hit just as easily.
 *
 * Keyed by username alone, not by the connection's address: this project
 * has no per-connection identity to key by yet (Milestone 12's `Session`
 * does not read a peer address), and a real deployment would want both —
 * a named, narrow gap rather than a claim of complete protection.
 */
final class LoginThrottle
{
    /** @var array<string, array{failures: int, lockedUntil: float}> */
    private array $state = [];

    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly float $baseDelaySeconds = 1.0,
        private readonly float $maxDelaySeconds = 60.0,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function isLocked(string $username): bool
    {
        $entry = $this->state[$username] ?? null;

        return $entry !== null && $entry['lockedUntil'] > $this->now();
    }

    public function recordFailure(string $username): void
    {
        $entry = $this->state[$username] ?? ['failures' => 0, 'lockedUntil' => 0.0];
        $entry['failures']++;

        if ($entry['failures'] > $this->maxAttempts) {
            $overBy = $entry['failures'] - $this->maxAttempts;
            $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * 2 ** ($overBy - 1));
            $entry['lockedUntil'] = $this->now() + $delay;
        }

        $this->state[$username] = $entry;
    }

    public function recordSuccess(string $username): void
    {
        unset($this->state[$username]);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}

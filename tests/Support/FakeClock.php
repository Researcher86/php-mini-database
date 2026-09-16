<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use PhpMiniDatabase\Support\Clock;

/**
 * A clock frozen at whatever instant a test constructs it with — until
 * `advanceBy()` moves it forward, for a test that needs to prove something
 * changes once enough time has passed (`Network\Auth\LoginThrottle`'s
 * lockout expiring, above all) without an actual `sleep()`.
 */
final class FakeClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2024-01-01 00:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceBy(float $seconds): void
    {
        // DateTimeImmutable::modify()'s relative formats do not parse a
        // fractional "seconds" unit correctly (it is read as something
        // else entirely, silently) - microseconds, as an integer, is the
        // unit that actually works.
        $this->now = $this->now->modify(sprintf('+%d microseconds', (int) round($seconds * 1_000_000)));
    }
}

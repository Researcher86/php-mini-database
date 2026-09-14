<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use PhpMiniDatabase\Support\Clock;

/** A clock frozen at whatever instant a test constructs it with. */
final readonly class FakeClock implements Clock
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
}

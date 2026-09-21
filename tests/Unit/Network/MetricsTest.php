<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\Metrics;
use PhpMiniDatabase\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function testCountersStartAtZero(): void
    {
        $metrics = new Metrics();

        self::assertSame(0, $metrics->totalConnections());
        self::assertSame(0, $metrics->totalQueries());
        self::assertSame(0, $metrics->totalErrors());
    }

    public function testRecordingIncrementsTheRightCounter(): void
    {
        $metrics = new Metrics();

        $metrics->recordConnection();
        $metrics->recordConnection();
        $metrics->recordQuery();
        $metrics->recordError();

        self::assertSame(2, $metrics->totalConnections());
        self::assertSame(1, $metrics->totalQueries());
        self::assertSame(1, $metrics->totalErrors());
    }

    public function testUptimeGrowsWithTheClock(): void
    {
        $clock = new FakeClock();
        $metrics = new Metrics($clock);

        self::assertSame(0.0, $metrics->uptimeSeconds());

        $clock->advanceBy(10.0);

        self::assertEqualsWithDelta(10.0, $metrics->uptimeSeconds(), 0.001);
    }

    public function testQueriesPerSecondIsAnAverageSinceStart(): void
    {
        $clock = new FakeClock();
        $metrics = new Metrics($clock);

        $clock->advanceBy(10.0);

        for ($i = 0; $i < 20; $i++) {
            $metrics->recordQuery();
        }

        self::assertEqualsWithDelta(2.0, $metrics->queriesPerSecond(), 0.001);
    }

    public function testQueriesPerSecondIsZeroBeforeAnyTimeHasPassed(): void
    {
        $metrics = new Metrics(new FakeClock());

        $metrics->recordQuery();

        self::assertSame(0.0, $metrics->queriesPerSecond());
    }
}

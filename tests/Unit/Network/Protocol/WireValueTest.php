<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Protocol;

use DateTimeImmutable;
use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\WireValue;
use PHPUnit\Framework\TestCase;

final class WireValueTest extends TestCase
{
    private function assertRoundTrips(mixed $value): void
    {
        $encoded = WireValue::encode($value);
        [$decoded, $offset] = WireValue::decode($encoded);

        self::assertSame($value, $decoded);
        self::assertSame(strlen($encoded), $offset);
    }

    public function testNullRoundTrips(): void
    {
        $this->assertRoundTrips(null);
    }

    public function testTrueRoundTrips(): void
    {
        $this->assertRoundTrips(true);
    }

    public function testFalseRoundTrips(): void
    {
        $this->assertRoundTrips(false);
    }

    public function testZeroRoundTrips(): void
    {
        $this->assertRoundTrips(0);
    }

    public function testAPositiveIntRoundTrips(): void
    {
        $this->assertRoundTrips(42);
    }

    public function testANegativeIntRoundTrips(): void
    {
        $this->assertRoundTrips(-42);
    }

    public function testTheMinimumIntRoundTrips(): void
    {
        $this->assertRoundTrips(PHP_INT_MIN);
    }

    public function testTheMaximumIntRoundTrips(): void
    {
        $this->assertRoundTrips(PHP_INT_MAX);
    }

    public function testAFloatRoundTrips(): void
    {
        $this->assertRoundTrips(3.14);
    }

    public function testANegativeFloatRoundTrips(): void
    {
        $this->assertRoundTrips(-0.5);
    }

    public function testAnEmptyStringRoundTrips(): void
    {
        $this->assertRoundTrips('');
    }

    public function testAStringRoundTrips(): void
    {
        $this->assertRoundTrips('hello');
    }

    public function testANonUtf8StringRoundTrips(): void
    {
        // A binary protocol needs no escaping trick for this, unlike
        // Transaction\Wal's JSON - the length prefix is all a BLOB's bytes
        // ever needed.
        $this->assertRoundTrips("\xFF\xFE binary");
    }

    public function testADateTimeRoundTripsToTheMicrosecond(): void
    {
        $value = new DateTimeImmutable('2026-03-14 15:09:26.535897');

        [$decoded] = WireValue::decode(WireValue::encode($value));

        self::assertInstanceOf(DateTimeImmutable::class, $decoded);
        self::assertSame($value->format('Y-m-d H:i:s.u'), $decoded->format('Y-m-d H:i:s.u'));
    }

    public function testMultipleValuesStitchBackInOrder(): void
    {
        $encoded = WireValue::encode(1) . WireValue::encode('two') . WireValue::encode(null);

        [$a, $offset] = WireValue::decode($encoded, 0);
        [$b, $offset] = WireValue::decode($encoded, $offset);
        [$c, $offset] = WireValue::decode($encoded, $offset);

        self::assertSame([1, 'two', null], [$a, $b, $c]);
        self::assertSame(strlen($encoded), $offset);
    }

    public function testAnEmptyBufferIsRejected(): void
    {
        $this->expectException(ProtocolException::class);
        WireValue::decode('');
    }

    public function testAnUnknownTagIsRejected(): void
    {
        $this->expectException(ProtocolException::class);
        WireValue::decode("\xFF");
    }

    public function testATruncatedStringIsRejected(): void
    {
        $encoded = WireValue::encode('hello');

        $this->expectException(ProtocolException::class);
        WireValue::decode(substr($encoded, 0, -2));
    }

    public function testATruncatedFloatIsRejected(): void
    {
        $encoded = WireValue::encode(1.5);

        $this->expectException(ProtocolException::class);
        WireValue::decode(substr($encoded, 0, -3));
    }
}

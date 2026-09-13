<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Schema\Type;

use MiniDatabase\Exception\TypeException;
use MiniDatabase\Schema\Type\BigIntType;
use MiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class BigIntTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new BigIntType();
    }

    public function testCastAcceptsInts(): void
    {
        self::assertSame(PHP_INT_MAX, $this->type->cast(PHP_INT_MAX));
        self::assertSame(0, $this->type->cast('0'));
    }

    public function testCastAcceptsIntegralFloats(): void
    {
        self::assertSame(5, $this->type->cast(5.0));
    }

    public function testCastRejectsOutOfRangeNumericString(): void
    {
        $this->expectException(TypeException::class);
        $this->type->cast('9223372036854775808');
    }

    public function testCastRejectsIncompatibleValues(): void
    {
        $this->expectException(TypeException::class);
        $this->type->cast('abc');
    }

    public function testRoundTripAtBoundaries(): void
    {
        foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $value) {
            $encoded = $this->type->encode($value);
            self::assertSame($value, $this->type->decode($encoded)[0]);
        }
    }

    public function testBytesAreOrderedBigEndian(): void
    {
        self::assertSame(
            "\x80\x00\x00\x00\x00\x00\x00\x01",
            $this->type->encode(1),
        );
        self::assertSame(
            "\x7f\xff\xff\xff\xff\xff\xff\xff",
            $this->type->encode(-1),
        );
    }

    public function testDecodeRejectsTruncatedBuffer(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x00\x00\x00\x00\x00");
    }
}

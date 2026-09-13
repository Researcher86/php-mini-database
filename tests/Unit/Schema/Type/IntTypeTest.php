<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class IntTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new IntType();
    }

    public function testCastAcceptsInts(): void
    {
        self::assertSame(42, $this->type->cast(42));
        self::assertSame(-42, $this->type->cast(-42));
    }

    public function testCastAcceptsNumericStringsAndIntegralFloats(): void
    {
        self::assertSame(42, $this->type->cast('42'));
        self::assertSame(-42, $this->type->cast('-42'));
        self::assertSame(7, $this->type->cast(7.0));
    }

    public function testCastRejectsOutOfRangeValues(): void
    {
        $this->expectException(TypeException::class);
        $this->type->cast(3000000000);
    }

    public function testCastRejectsIncompatibleValues(): void
    {
        $thrown = 0;

        foreach ([3.14, 'abc', [], true] as $value) {
            try {
                $this->type->cast($value);
            } catch (TypeException) {
                $thrown++;
            }
        }

        self::assertSame(4, $thrown);
    }

    public function testRoundTripAtBoundaries(): void
    {
        foreach ([-2147483648, -1, 0, 1, 2147483647] as $value) {
            $encoded = $this->type->encode($value);
            self::assertSame($value, $this->type->decode($encoded)[0]);
        }
    }

    public function testBytesAreOrderedBigEndian(): void
    {
        self::assertSame("\x80\x00\x00\x01", $this->type->encode(1));
        self::assertSame("\xff\xff\xff\xff", $this->type->encode(2147483647));
        self::assertSame("\x7f\xff\xff\xff", $this->type->encode(-1));
    }

    public function testDecodeReadsAValueAndAdvancesOffset(): void
    {
        $encoded = "\xAA\xBB" . $this->type->encode(1234);

        [$value, $offset] = $this->type->decode($encoded, 2);

        self::assertSame(1234, $value);
        self::assertSame(6, $offset);
    }

    public function testDecodeRejectsTruncatedBuffer(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x01");
    }
}

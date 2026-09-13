<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\DecimalType;
use PhpMiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class DecimalTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new DecimalType(10, 2);
    }

    public function testNameIncludesPrecisionAndScale(): void
    {
        self::assertSame('DECIMAL(10,2)', $this->type->name());
    }

    public function testCastNormalizesToFixedScale(): void
    {
        self::assertSame('123.40', $this->type->cast('123.4'));
        self::assertSame('3.00', $this->type->cast(3));
        self::assertSame('-0.05', $this->type->cast('-0.05'));
        self::assertSame('0.00', $this->type->cast('000.00'));
        self::assertSame('5.25', $this->type->cast(5.25));
    }

    public function testCastRejectsTooManyFractionDigits(): void
    {
        $this->expectException(TypeException::class);
        $this->type->cast('123.456');
    }

    public function testCastRejectsExcessIntegerDigits(): void
    {
        $this->expectException(TypeException::class);
        $this->type->cast('100000000');
    }

    public function testCastRejectsGarbage(): void
    {
        $thrown = 0;

        foreach (['1e3', 'abc', '+123..4', '1,5'] as $value) {
            try {
                $this->type->cast($value);
            } catch (TypeException) {
                $thrown++;
            }
        }

        self::assertSame(4, $thrown);
    }

    public function testRoundTrip(): void
    {
        foreach (['0.00', '1.00', '123.40', '-0.05', '-99999999.99', '99999999.99'] as $value) {
            $encoded = $this->type->encode($value);
            self::assertSame($value, $this->type->decode($encoded)[0]);
        }
    }

    public function testBytesAreSortableFixedPoint(): void
    {
        self::assertLessThan(
            $this->type->encode('0.00'),
            $this->type->encode('-1.00'),
        );
        self::assertLessThan(
            $this->type->encode('2.00'),
            $this->type->encode('1.00'),
        );
    }

    public function testConstructorRejectsBadPrecisionAndScale(): void
    {
        $this->expectException(TypeException::class);
        new DecimalType(0, 2);
    }

    public function testDecodeRejectsTruncatedBuffer(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x00\x00\x00\x00\x00");
    }
}

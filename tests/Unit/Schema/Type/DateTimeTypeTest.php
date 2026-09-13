<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use DateTimeImmutable;
use DateTimeZone;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\DateTimeType;
use PhpMiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class DateTimeTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new DateTimeType();
    }

    public function testCastParsesCommonStringFormats(): void
    {
        $date = $this->type->cast('2024-06-15 14:03:02');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2024-06-15 14:03:02', $date->format('Y-m-d H:i:s'));
    }

    public function testCastParsesMicroseconds(): void
    {
        $date = $this->type->cast('2024-06-15 14:03:02.250000');

        self::assertSame('250000', $date->format('u'));
    }

    public function testCastKeepsTheInstantFromTimezoneValue(): void
    {
        $input = new DateTimeImmutable('2024-06-15 14:03:02', new DateTimeZone('Europe/Moscow'));

        self::assertSame('11:03:02', $this->type->cast($input)->setTimezone(new DateTimeZone('UTC'))->format('H:i:s'));
    }

    public function testCastStrictlyRejectsGarbage(): void
    {
        $thrown = 0;

        foreach (['2024-13-01 00:00:00', 'not a date', []] as $value) {
            try {
                $this->type->cast($value);
            } catch (TypeException) {
                $thrown++;
            }
        }

        self::assertSame(3, $thrown);
    }

    public function testRoundTripAroundEpoch(): void
    {
        foreach (['1969-12-31 23:59:59.999999', '1970-01-01 00:00:00.000000', '1970-01-01 00:00:00.000001', '2024-06-15 14:03:02.250000'] as $value) {
            $encoded = $this->type->encode($this->type->cast($value));
            $decoded = $this->type->decode($encoded)[0]->setTimezone(new DateTimeZone('UTC'));

            self::assertSame($value, $decoded->format('Y-m-d H:i:s.u'));
        }
    }

    public function testBytesAreMicrosecondsSinceEpoch(): void
    {
        self::assertSame(
            "\x80\x00\x00\x00\x00\x00\x00\x00",
            $this->type->encode($this->type->cast('1970-01-01 00:00:00')),
        );
    }

    public function testDecodeRejectsTruncatedBuffer(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x00\x00\x00\x00\x00");
    }
}

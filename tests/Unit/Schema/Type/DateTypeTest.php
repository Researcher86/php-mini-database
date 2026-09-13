<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use DateTimeImmutable;
use DateTimeZone;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\DateType;
use PhpMiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class DateTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new DateType();
    }

    public function testCastParsesDateStrings(): void
    {
        $date = $this->type->cast('2024-06-15');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2024-06-15', $date->format('Y-m-d'));
        self::assertSame('00:00:00', $date->format('H:i:s'));
    }

    public function testCastTakesCalendarDateFromTimezonedValue(): void
    {
        $input = new DateTimeImmutable('2024-06-15 23:59:00', new DateTimeZone('Europe/Moscow'));

        self::assertSame('2024-06-15', $this->type->cast($input)->format('Y-m-d'));
    }

    public function testCastRejectsInvalidDatesAndGarbage(): void
    {
        $thrown = 0;

        foreach (['2024-13-01', '2024-02-30', 'yesterday', 42, true] as $value) {
            try {
                $this->type->cast($value);
            } catch (TypeException) {
                $thrown++;
            }
        }

        self::assertSame(5, $thrown);
    }

    public function testRoundTrip(): void
    {
        foreach (['0001-01-01', '1970-01-01', '1969-12-31', '2024-06-15', '9999-12-31'] as $value) {
            $encoded = $this->type->encode($this->type->cast($value));
            self::assertSame($value, $this->type->decode($encoded)[0]->format('Y-m-d'));
        }
    }

    public function testBytesAreOrderedDayCount(): void
    {
        $epoch = $this->type->cast('1970-01-01');
        $nextDay = $this->type->cast('1970-01-02');

        self::assertSame("\x80\x00\x00\x00", $this->type->encode($epoch));
        self::assertSame("\x80\x00\x00\x01", $this->type->encode($nextDay));
    }

    public function testDecodeRejectsTruncatedBuffer(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x01");
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Operator\Limit;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class LimitTest extends TestCase
{
    /** @return list<Row> */
    private function rows(int $count): array
    {
        return array_map(static fn (int $i): Row => new Row(['n' => $i]), range(0, $count - 1));
    }

    /** @return list<mixed> */
    private function ns(Limit $limit): array
    {
        return array_map(static fn (Row $r) => $r->get('n'), iterator_to_array($limit, false));
    }

    public function testLimitAlone(): void
    {
        self::assertSame([0, 1, 2], $this->ns(new Limit(new ListOperator($this->rows(10)), limit: 3)));
    }

    public function testOffsetAlone(): void
    {
        self::assertSame([3, 4], $this->ns(new Limit(new ListOperator($this->rows(5)), offset: 3)));
    }

    public function testLimitAndOffsetTogether(): void
    {
        self::assertSame([2, 3], $this->ns(new Limit(new ListOperator($this->rows(10)), limit: 2, offset: 2)));
    }

    public function testNoLimitOrOffsetPassesEverythingThrough(): void
    {
        self::assertSame([0, 1, 2], $this->ns(new Limit(new ListOperator($this->rows(3)))));
    }

    public function testAZeroLimitYieldsNothingWithoutTouchingTheSource(): void
    {
        self::assertSame([], $this->ns(new Limit(new ListOperator($this->rows(5)), limit: 0)));
    }

    public function testAnOffsetPastTheEndYieldsNothing(): void
    {
        self::assertSame([], $this->ns(new Limit(new ListOperator($this->rows(3)), offset: 10)));
    }

    public function testALimitLargerThanTheSourceYieldsEverything(): void
    {
        self::assertSame([0, 1, 2], $this->ns(new Limit(new ListOperator($this->rows(3)), limit: 100)));
    }
}

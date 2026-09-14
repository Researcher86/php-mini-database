<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Operator\Distinct;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class DistinctTest extends TestCase
{
    public function testDropsExactDuplicateRows(): void
    {
        $source = new ListOperator([
            new Row(['status' => 'active']),
            new Row(['status' => 'active']),
            new Row(['status' => 'inactive']),
        ]);

        $rows = iterator_to_array(new Distinct($source), false);

        self::assertSame(['active', 'inactive'], array_map(static fn (Row $r) => $r->get('status'), $rows));
    }

    public function testRowsWithTheSameValuesInDifferentColumnsAreNotConfused(): void
    {
        $source = new ListOperator([
            new Row(['a' => 1, 'b' => 2]),
            new Row(['a' => 2, 'b' => 1]),
        ]);

        self::assertCount(2, iterator_to_array(new Distinct($source), false));
    }

    public function testAnEmptySourceYieldsNothing(): void
    {
        self::assertSame([], iterator_to_array(new Distinct(new ListOperator([])), false));
    }

    public function testDistinctRowsKeepTheirRelativeOrder(): void
    {
        $source = new ListOperator([
            new Row(['status' => 'b']),
            new Row(['status' => 'a']),
            new Row(['status' => 'b']),
        ]);

        $rows = iterator_to_array(new Distinct($source), false);

        self::assertSame(['b', 'a'], array_map(static fn (Row $r) => $r->get('status'), $rows));
    }
}

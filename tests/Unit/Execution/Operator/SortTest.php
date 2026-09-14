<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use Closure;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Execution\Operator\Sort;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\OrderByItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class SortTest extends TestCase
{
    /** @return list<OrderByItem> */
    private function orderBy(string $sql): array
    {
        $statement = Parser::parseOne("SELECT * FROM t ORDER BY {$sql}");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->orderBy;
    }

    /** @return list<mixed> */
    private function ns(Sort $sort): array
    {
        return array_map(static fn (Row $r) => $r->get('n'), iterator_to_array($sort, false));
    }

    private function context(): Closure
    {
        return static fn (Row $row): RowContext => new RowContext($row, 't');
    }

    public function testAscendingOrder(): void
    {
        $source = new ListOperator([new Row(['n' => 3]), new Row(['n' => 1]), new Row(['n' => 2])]);

        self::assertSame([1, 2, 3], $this->ns(new Sort($source, $this->orderBy('n ASC'), new Evaluator(), $this->context())));
    }

    public function testDescendingOrder(): void
    {
        $source = new ListOperator([new Row(['n' => 1]), new Row(['n' => 3]), new Row(['n' => 2])]);

        self::assertSame([3, 2, 1], $this->ns(new Sort($source, $this->orderBy('n DESC'), new Evaluator(), $this->context())));
    }

    public function testNullsSortFirstAscendingAndLastDescending(): void
    {
        $source = new ListOperator([new Row(['n' => 1]), new Row(['n' => null]), new Row(['n' => 2])]);

        self::assertSame([null, 1, 2], $this->ns(new Sort($source, $this->orderBy('n ASC'), new Evaluator(), $this->context())));
        self::assertSame([2, 1, null], $this->ns(new Sort($source, $this->orderBy('n DESC'), new Evaluator(), $this->context())));
    }

    public function testASecondKeyBreaksTiesInTheFirst(): void
    {
        $source = new ListOperator([
            new Row(['a' => 1, 'n' => 2]),
            new Row(['a' => 1, 'n' => 1]),
            new Row(['a' => 0, 'n' => 5]),
        ]);

        $sort = new Sort($source, $this->orderBy('a ASC, n ASC'), new Evaluator(), $this->context());

        self::assertSame([5, 1, 2], array_map(static fn (Row $r) => $r->get('n'), iterator_to_array($sort, false)));
    }

    public function testEqualRowsKeepTheirOriginalOrder(): void
    {
        $source = new ListOperator([
            new Row(['a' => 1, 'label' => 'first']),
            new Row(['a' => 1, 'label' => 'second']),
        ]);

        $sort = new Sort($source, $this->orderBy('a ASC'), new Evaluator(), $this->context());

        self::assertSame(['first', 'second'], array_map(static fn (Row $r) => $r->get('label'), iterator_to_array($sort, false)));
    }

    public function testAnEmptySourceYieldsNothing(): void
    {
        self::assertSame([], $this->ns(new Sort(new ListOperator([]), $this->orderBy('n ASC'), new Evaluator(), $this->context())));
    }
}

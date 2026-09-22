<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use Closure;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Execution\Operator\Aggregate;
use PhpMiniDatabase\Execution\Operator\Project;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class AggregateTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator();
    }

    private function context(): Closure
    {
        return static fn (Row $row): RowContext => new RowContext($row, 't');
    }

    /** @return list<SelectItem> */
    private function items(string $sql): array
    {
        $statement = Parser::parseOne("SELECT {$sql} FROM t");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->columns;
    }

    /** @return list<Expression> */
    private function groupBy(string $sql): array
    {
        $statement = Parser::parseOne("SELECT 1 FROM t GROUP BY {$sql}");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->groupBy;
    }

    private function having(string $sql): Expression
    {
        $statement = Parser::parseOne("SELECT 1 FROM t GROUP BY dummy HAVING {$sql}");
        self::assertInstanceOf(SelectStatement::class, $statement);
        self::assertNotNull($statement->having);

        return $statement->having;
    }

    /**
     * @param list<SelectItem> $items
     *
     * @return list<string>
     */
    private function labelsFor(array $items): array
    {
        return array_map(static fn (SelectItem $i, int $n): string => Project::label($i, $n), $items, array_keys($items));
    }

    /**
     * @param list<Row>         $sourceRows
     * @param list<Expression>  $groupBy
     *
     * @return list<Row>
     */
    private function aggregate(array $sourceRows, array $groupBy, string $itemsSql, ?Expression $having = null): array
    {
        $items = $this->items($itemsSql);

        $aggregate = new Aggregate(
            new ListOperator($sourceRows),
            $groupBy,
            $items,
            $this->labelsFor($items),
            $having,
            $this->evaluator,
            $this->context(),
        );

        return iterator_to_array($aggregate, false);
    }

    public function testCountStarOverEverything(): void
    {
        $rows = $this->aggregate([new Row(['a' => 1]), new Row(['a' => 2]), new Row(['a' => 3])], [], 'COUNT(*)');

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->get('COUNT'));
    }

    public function testCountStarOverAnEmptySourceIsZeroNotZeroRows(): void
    {
        $rows = $this->aggregate([], [], 'COUNT(*)');

        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]->get('COUNT'));
    }

    public function testGroupByOverAnEmptySourceProducesNoGroups(): void
    {
        self::assertSame([], $this->aggregate([], $this->groupBy('status'), 'status, COUNT(*)'));
    }

    public function testGroupByPartitionsRowsAndCountsEach(): void
    {
        $rows = $this->aggregate(
            [
                new Row(['status' => 'active']),
                new Row(['status' => 'active']),
                new Row(['status' => 'inactive']),
            ],
            $this->groupBy('status'),
            'status, COUNT(*)',
        );

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row->get('status')] = $row->get('COUNT');
        }

        self::assertSame(['active' => 2, 'inactive' => 1], $byStatus);
    }

    public function testSumSkipsNulls(): void
    {
        $rows = $this->aggregate([new Row(['x' => 1]), new Row(['x' => null]), new Row(['x' => 3])], [], 'SUM(x)');

        self::assertSame(4, $rows[0]->get('SUM'));
    }

    public function testSumOverAllNullsIsNull(): void
    {
        $rows = $this->aggregate([new Row(['x' => null])], [], 'SUM(x)');

        self::assertNull($rows[0]->get('SUM'));
    }

    public function testAvgMinMax(): void
    {
        $rows = $this->aggregate(
            [new Row(['x' => 1]), new Row(['x' => 2]), new Row(['x' => 3])],
            [],
            'AVG(x), MIN(x), MAX(x)',
        );

        self::assertSame(2, $rows[0]->get('AVG'));
        self::assertSame(1, $rows[0]->get('MIN'));
        self::assertSame(3, $rows[0]->get('MAX'));
    }

    public function testCountDistinct(): void
    {
        $rows = $this->aggregate(
            [new Row(['x' => 1]), new Row(['x' => 1]), new Row(['x' => 2])],
            [],
            'COUNT(DISTINCT x)',
        );

        self::assertSame(2, $rows[0]->get('COUNT'));
    }

    /**
     * An aggregate nested inside a larger expression - "COUNT(*) > 1" - is
     * exactly HAVING's typical shape: not a bare aggregate call, but one
     * wrapped in a comparison.
     */
    public function testHavingFiltersGroupsByAnAggregateComparison(): void
    {
        $rows = $this->aggregate(
            [
                new Row(['status' => 'active']),
                new Row(['status' => 'active']),
                new Row(['status' => 'inactive']),
            ],
            $this->groupBy('status'),
            'status, COUNT(*)',
            $this->having('COUNT(*) > 1'),
        );

        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]->get('status'));
    }

    public function testArithmeticOverAnAggregateIsSubstitutedCorrectly(): void
    {
        $rows = $this->aggregate([new Row(['x' => 1]), new Row(['x' => 2])], [], 'SUM(x) + 10');

        self::assertSame(13, $rows[0]->get('column1'));
    }
}

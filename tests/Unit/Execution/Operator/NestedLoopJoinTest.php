<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Operator\NestedLoopJoin;
use PhpMiniDatabase\Execution\Operator\Qualify;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class NestedLoopJoinTest extends TestCase
{
    private function on(string $sql): Expression
    {
        $statement = Parser::parseOne("SELECT * FROM t WHERE {$sql}");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->where;
    }

    private function users(): Qualify
    {
        return new Qualify(new ListOperator([
            new Row(['id' => 1, 'name' => 'alice']),
            new Row(['id' => 2, 'name' => 'bob']),
        ]), 'u');
    }

    private function orders(): Qualify
    {
        return new Qualify(new ListOperator([
            new Row(['id' => 100, 'user_id' => 1, 'total' => 50]),
            new Row(['id' => 101, 'user_id' => 1, 'total' => 30]),
        ]), 'o');
    }

    public function testInnerJoinMatchesOnTheCondition(): void
    {
        $join = new NestedLoopJoin($this->users(), $this->orders(), false, $this->on('u.id = o.user_id'), new Evaluator(), []);

        $rows = iterator_to_array($join, false);

        self::assertCount(2, $rows);
        self::assertSame(['alice', 'alice'], array_map(static fn (Row $r) => $r->get('u.name'), $rows));
        self::assertSame([50, 30], array_map(static fn (Row $r) => $r->get('o.total'), $rows));
    }

    public function testInnerJoinDropsUnmatchedRows(): void
    {
        $join = new NestedLoopJoin($this->users(), $this->orders(), false, $this->on('u.id = o.user_id'), new Evaluator(), []);

        $names = array_map(static fn (Row $r) => $r->get('u.name'), iterator_to_array($join, false));

        self::assertNotContains('bob', $names);
    }

    public function testLeftJoinKeepsAnUnmatchedLeftRowWithNulls(): void
    {
        $rightColumnKeys = ['o.id', 'o.user_id', 'o.total'];
        $join = new NestedLoopJoin($this->users(), $this->orders(), true, $this->on('u.id = o.user_id'), new Evaluator(), [], $rightColumnKeys);

        $rows = iterator_to_array($join, false);
        $bob = array_values(array_filter($rows, static fn (Row $r) => $r->get('u.name') === 'bob'));

        self::assertCount(1, $bob);
        self::assertNull($bob[0]->get('o.total'));
        self::assertTrue($bob[0]->has('o.total'));
    }

    public function testEmptyLeftSideYieldsNothing(): void
    {
        $join = new NestedLoopJoin(new Qualify(new ListOperator([]), 'u'), $this->orders(), false, $this->on('u.id = o.user_id'), new Evaluator(), []);

        self::assertSame([], iterator_to_array($join, false));
    }

    public function testInnerJoinWithAnEmptyRightSideYieldsNothing(): void
    {
        $join = new NestedLoopJoin($this->users(), new Qualify(new ListOperator([]), 'o'), false, $this->on('u.id = o.user_id'), new Evaluator(), []);

        self::assertSame([], iterator_to_array($join, false));
    }

    public function testLeftJoinWithAnEmptyRightSideNullPadsEveryLeftRow(): void
    {
        $join = new NestedLoopJoin(
            $this->users(),
            new Qualify(new ListOperator([]), 'o'),
            true,
            $this->on('u.id = o.user_id'),
            new Evaluator(),
            [],
            ['o.id', 'o.user_id', 'o.total'],
        );

        $rows = iterator_to_array($join, false);

        self::assertCount(2, $rows);
        self::assertNull($rows[0]->get('o.total'));
        self::assertNull($rows[1]->get('o.total'));
    }
}

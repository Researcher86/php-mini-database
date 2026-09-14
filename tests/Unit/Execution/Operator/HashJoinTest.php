<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Operator\HashJoin;
use PhpMiniDatabase\Execution\Operator\Qualify;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class HashJoinTest extends TestCase
{
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

    public function testMatchesOnEqualKeys(): void
    {
        $join = new HashJoin($this->users(), $this->orders(), 'u.id', 'o.user_id');

        $rows = iterator_to_array($join, false);

        self::assertCount(2, $rows);
        self::assertSame([50, 30], array_map(static fn (Row $r) => $r->get('o.total'), $rows));
    }

    public function testUnmatchedRowsAreDropped(): void
    {
        $join = new HashJoin($this->users(), $this->orders(), 'u.id', 'o.user_id');

        $names = array_map(static fn (Row $r) => $r->get('u.name'), iterator_to_array($join, false));

        self::assertNotContains('bob', $names);
    }

    public function testNullKeysNeverMatchAnything(): void
    {
        $left = new Qualify(new ListOperator([new Row(['id' => null])]), 'u');
        $right = new Qualify(new ListOperator([new Row(['user_id' => null])]), 'o');

        $join = new HashJoin($left, $right, 'u.id', 'o.user_id');

        self::assertSame([], iterator_to_array($join, false));
    }

    public function testAnEmptySideYieldsNothing(): void
    {
        $join = new HashJoin(new Qualify(new ListOperator([]), 'u'), $this->orders(), 'u.id', 'o.user_id');

        self::assertSame([], iterator_to_array($join, false));
    }
}

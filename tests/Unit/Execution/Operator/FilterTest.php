<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Operator\Filter;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    private function whereExpression(string $sql): Expression
    {
        $statement = Parser::parseOne("SELECT * FROM t WHERE {$sql}");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->where;
    }

    public function testYieldsOnlyMatchingRows(): void
    {
        $source = new ListOperator([new Row(['age' => 10]), new Row(['age' => 20]), new Row(['age' => 30])]);
        $filter = new Filter($source, $this->whereExpression('age >= 20'), new Evaluator(), 't');

        $ages = array_map(static fn (Row $r) => $r->get('age'), iterator_to_array($filter, false));

        self::assertSame([20, 30], $ages);
    }

    /**
     * A NULL predicate result excludes the row exactly like FALSE would -
     * three-valued logic applied at the filter boundary.
     */
    public function testANullPredicateExcludesTheRow(): void
    {
        $source = new ListOperator([new Row(['age' => null]), new Row(['age' => 20])]);
        $filter = new Filter($source, $this->whereExpression('age > 10'), new Evaluator(), 't');

        self::assertCount(1, iterator_to_array($filter, false));
    }

    public function testAnEmptySourceYieldsNothing(): void
    {
        $filter = new Filter(new ListOperator([]), $this->whereExpression('age > 10'), new Evaluator(), 't');

        self::assertSame([], iterator_to_array($filter, false));
    }
}

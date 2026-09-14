<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\ScalarSubquery;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\From\DerivedTable;
use PhpMiniDatabase\Sql\Ast\From\Join;
use PhpMiniDatabase\Sql\Ast\From\JoinType;
use PhpMiniDatabase\Sql\Ast\From\TableReference;
use PhpMiniDatabase\Sql\Ast\OrderDirection;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ParserSelectTest extends TestCase
{
    private function select(string $sql): SelectStatement
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement;
    }

    public function testSelectStar(): void
    {
        $select = $this->select('SELECT * FROM users');

        self::assertEquals(new Star(), $select->columns[0]->expression);
        self::assertEquals(new TableReference('users'), $select->from);
    }

    public function testSelectQualifiedStar(): void
    {
        $select = $this->select('SELECT u.* FROM users u');

        self::assertEquals(new Star('u'), $select->columns[0]->expression);
    }

    public function testSelectColumnListWithAliases(): void
    {
        $select = $this->select('SELECT id, email AS address, age total FROM users');

        self::assertEquals(new ColumnRef('id'), $select->columns[0]->expression);
        self::assertNull($select->columns[0]->alias);
        self::assertSame('address', $select->columns[1]->alias);
        self::assertSame('total', $select->columns[2]->alias);
    }

    public function testTableAliasWithAndWithoutAs(): void
    {
        self::assertEquals(new TableReference('users', 'u'), $this->select('SELECT 1 FROM users AS u')->from);
        self::assertEquals(new TableReference('users', 'u'), $this->select('SELECT 1 FROM users u')->from);
    }

    public function testWhereClause(): void
    {
        $select = $this->select('SELECT * FROM users WHERE age >= 18');

        self::assertEquals(
            new BinaryOp(new ColumnRef('age'), BinaryOperator::GREATER_THAN_OR_EQUAL, new Literal(18)),
            $select->where,
        );
    }

    public function testDistinct(): void
    {
        self::assertTrue($this->select('SELECT DISTINCT status FROM users')->distinct);
        self::assertFalse($this->select('SELECT status FROM users')->distinct);
    }

    public function testGroupByHaving(): void
    {
        $select = $this->select('SELECT status, COUNT(*) FROM users GROUP BY status HAVING COUNT(*) > 1');

        self::assertEquals([new ColumnRef('status')], $select->groupBy);
        self::assertEquals(
            new BinaryOp(new FunctionCall('COUNT', [new Star()]), BinaryOperator::GREATER_THAN, new Literal(1)),
            $select->having,
        );
    }

    public function testOrderByLimitOffset(): void
    {
        $select = $this->select('SELECT id FROM users ORDER BY email DESC, id LIMIT 10 OFFSET 5');

        self::assertEquals(new ColumnRef('email'), $select->orderBy[0]->expression);
        self::assertSame(OrderDirection::DESC, $select->orderBy[0]->direction);
        self::assertSame(OrderDirection::ASC, $select->orderBy[1]->direction);
        self::assertSame(10, $select->limit);
        self::assertSame(5, $select->offset);
    }

    public function testInnerJoinExplicitAndImplicit(): void
    {
        $joined = $this->select('SELECT * FROM users u JOIN orders o ON o.user_id = u.id')->from;

        self::assertInstanceOf(Join::class, $joined);
        self::assertSame(JoinType::INNER, $joined->type);
        self::assertEquals(new TableReference('users', 'u'), $joined->left);
        self::assertEquals(new TableReference('orders', 'o'), $joined->right);

        $explicit = $this->select('SELECT * FROM users u INNER JOIN orders o ON o.user_id = u.id')->from;
        self::assertEquals($joined, $explicit);
    }

    public function testLeftAndRightJoinWithOptionalOuter(): void
    {
        $left = $this->select('SELECT * FROM users u LEFT JOIN orders o ON o.user_id = u.id')->from;
        self::assertInstanceOf(Join::class, $left);
        self::assertSame(JoinType::LEFT, $left->type);

        $leftOuter = $this->select('SELECT * FROM users u LEFT OUTER JOIN orders o ON o.user_id = u.id')->from;
        self::assertEquals($left, $leftOuter);

        $right = $this->select('SELECT * FROM users u RIGHT JOIN orders o ON o.user_id = u.id')->from;
        self::assertInstanceOf(Join::class, $right);
        self::assertSame(JoinType::RIGHT, $right->type);
    }

    public function testChainedJoinsNestLeftToRight(): void
    {
        $from = $this->select('SELECT * FROM a JOIN b ON b.a_id = a.id JOIN c ON c.b_id = b.id')->from;

        self::assertInstanceOf(Join::class, $from);
        self::assertInstanceOf(Join::class, $from->left);
        self::assertEquals(new TableReference('a'), $from->left->left);
        self::assertEquals(new TableReference('b'), $from->left->right);
        self::assertEquals(new TableReference('c'), $from->right);
    }

    public function testDerivedTableRequiresAndAcceptsAnAlias(): void
    {
        $from = $this->select('SELECT * FROM (SELECT id FROM users) AS t')->from;

        self::assertInstanceOf(DerivedTable::class, $from);
        self::assertSame('t', $from->alias);
        self::assertEquals(new TableReference('users'), $from->query->from);
    }

    public function testScalarSubqueryInWhere(): void
    {
        $select = $this->select('SELECT * FROM users WHERE age > (SELECT AVG(age) FROM users)');

        self::assertInstanceOf(BinaryOp::class, $select->where);
        self::assertInstanceOf(ScalarSubquery::class, $select->where->right);
    }

    public function testSelectWithNoFromClause(): void
    {
        $select = $this->select('SELECT 1 + 1');

        self::assertNull($select->from);
        self::assertEquals(new BinaryOp(new Literal(1), BinaryOperator::ADD, new Literal(1)), $select->columns[0]->expression);
    }

    public function testParenthesizedFromGroupsAJoin(): void
    {
        $from = $this->select('SELECT * FROM (a JOIN b ON b.a_id = a.id)')->from;

        self::assertInstanceOf(Join::class, $from);
    }
}

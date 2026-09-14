<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Between;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\InList;
use PhpMiniDatabase\Sql\Ast\Expression\InSubquery;
use PhpMiniDatabase\Sql\Ast\Expression\IsNull;
use PhpMiniDatabase\Sql\Ast\Expression\Like;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOperator;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ParserExpressionTest extends TestCase
{
    private function whereAsBinaryOp(string $expression): BinaryOp
    {
        $where = $this->where($expression);
        self::assertInstanceOf(BinaryOp::class, $where);

        return $where;
    }

    private function where(string $expression): Expression
    {
        $statement = Parser::parseOne("SELECT * FROM t WHERE {$expression}");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->where;
    }

    public function testMultiplicationBindsTighterThanAddition(): void
    {
        // 1 + 2 * 3 -> 1 + (2 * 3)
        self::assertEquals(
            new BinaryOp(
                new Literal(1),
                BinaryOperator::ADD,
                new BinaryOp(new Literal(2), BinaryOperator::MULTIPLY, new Literal(3)),
            ),
            $this->where('1 + 2 * 3'),
        );
    }

    public function testParenthesesOverridePrecedence(): void
    {
        self::assertEquals(
            new BinaryOp(
                new BinaryOp(new Literal(1), BinaryOperator::ADD, new Literal(2)),
                BinaryOperator::MULTIPLY,
                new Literal(3),
            ),
            $this->where('(1 + 2) * 3'),
        );
    }

    public function testAdditionIsLeftAssociative(): void
    {
        // 1 - 2 - 3 -> (1 - 2) - 3, not 1 - (2 - 3)
        self::assertEquals(
            new BinaryOp(
                new BinaryOp(new Literal(1), BinaryOperator::SUBTRACT, new Literal(2)),
                BinaryOperator::SUBTRACT,
                new Literal(3),
            ),
            $this->where('1 - 2 - 3'),
        );
    }

    public function testAndBindsTighterThanOr(): void
    {
        // a OR b AND c -> a OR (b AND c)
        self::assertEquals(
            new BinaryOp(
                new ColumnRef('a'),
                BinaryOperator::OR,
                new BinaryOp(new ColumnRef('b'), BinaryOperator::AND, new ColumnRef('c')),
            ),
            $this->where('a OR b AND c'),
        );
    }

    public function testComparisonBindsTighterThanAnd(): void
    {
        self::assertEquals(
            new BinaryOp(
                new BinaryOp(new ColumnRef('a'), BinaryOperator::EQUAL, new Literal(1)),
                BinaryOperator::AND,
                new BinaryOp(new ColumnRef('b'), BinaryOperator::EQUAL, new Literal(2)),
            ),
            $this->where('a = 1 AND b = 2'),
        );
    }

    public function testNotBindsToASingleComparisonNotTheWholeAndChain(): void
    {
        // NOT a AND b -> (NOT a) AND b
        self::assertEquals(
            new BinaryOp(new UnaryOp(UnaryOperator::NOT, new ColumnRef('a')), BinaryOperator::AND, new ColumnRef('b')),
            $this->where('NOT a AND b'),
        );
    }

    public function testUnaryMinus(): void
    {
        self::assertEquals(
            new BinaryOp(new ColumnRef('age'), BinaryOperator::EQUAL, new UnaryOp(UnaryOperator::NEGATE, new Literal(1))),
            $this->where('age = -1'),
        );
    }

    public function testInList(): void
    {
        self::assertEquals(
            new InList(new ColumnRef('id'), [new Literal(1), new Literal(2), new Literal(3)]),
            $this->where('id IN (1, 2, 3)'),
        );
    }

    public function testNotInList(): void
    {
        $result = $this->where('id NOT IN (1, 2)');

        self::assertInstanceOf(InList::class, $result);
        self::assertTrue($result->negated);
    }

    public function testInSubquery(): void
    {
        $result = $this->where('id IN (SELECT user_id FROM orders)');

        self::assertInstanceOf(InSubquery::class, $result);
        self::assertFalse($result->negated);
    }

    public function testBetween(): void
    {
        self::assertEquals(
            new Between(new ColumnRef('age'), new Literal(18), new Literal(65)),
            $this->where('age BETWEEN 18 AND 65'),
        );
    }

    public function testNotBetween(): void
    {
        $result = $this->where('age NOT BETWEEN 18 AND 65');

        self::assertInstanceOf(Between::class, $result);
        self::assertTrue($result->negated);
    }

    public function testLikeAndNotLike(): void
    {
        self::assertEquals(new Like(new ColumnRef('email'), new Literal('%@x.com')), $this->where("email LIKE '%@x.com'"));

        $result = $this->where("email NOT LIKE '%@x.com'");
        self::assertInstanceOf(Like::class, $result);
        self::assertTrue($result->negated);
    }

    public function testIsNullAndIsNotNull(): void
    {
        self::assertEquals(new IsNull(new ColumnRef('deleted_at')), $this->where('deleted_at IS NULL'));
        self::assertEquals(new IsNull(new ColumnRef('deleted_at'), true), $this->where('deleted_at IS NOT NULL'));
    }

    public function testFunctionCallWithNoArguments(): void
    {
        self::assertEquals(new FunctionCall('NOW'), $this->whereAsBinaryOp('NOW() = NOW()')->left);
    }

    public function testFunctionCallWithArguments(): void
    {
        self::assertEquals(
            new FunctionCall('UPPER', [new ColumnRef('email')]),
            $this->whereAsBinaryOp("UPPER(email) = 'A'")->left,
        );
    }

    public function testCountStarAndCountDistinct(): void
    {
        self::assertEquals(new FunctionCall('COUNT', [new Star()]), $this->whereAsBinaryOp('COUNT(*) > 0')->left);
        self::assertEquals(
            new FunctionCall('COUNT', [new ColumnRef('status')], distinct: true),
            $this->whereAsBinaryOp('COUNT(DISTINCT status) > 0')->left,
        );
    }

    public function testNiladicDateFunctionsNeedNoParentheses(): void
    {
        self::assertEquals(
            new BinaryOp(new ColumnRef('created_at'), BinaryOperator::LESS_THAN, new FunctionCall('CURRENT_TIMESTAMP')),
            $this->where('created_at < CURRENT_TIMESTAMP'),
        );
    }

    public function testQualifiedColumnReference(): void
    {
        self::assertEquals(new ColumnRef('id', 'u'), $this->whereAsBinaryOp('u.id = 1')->left);
    }

    public function testBooleanAndNullLiterals(): void
    {
        self::assertEquals(new Literal(true), $this->whereAsBinaryOp('active = TRUE')->right);
        self::assertEquals(new Literal(false), $this->whereAsBinaryOp('active = FALSE')->right);
        self::assertEquals(new Literal(null), $this->whereAsBinaryOp('deleted_at = NULL')->right);
    }
}

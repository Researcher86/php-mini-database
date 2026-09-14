<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql\Optimizer;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule\ConstantFolding;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Planner;
use PhpMiniDatabase\Tests\Support\PlanAssertions;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ConstantFoldingTest extends TestCase
{
    use PlanAssertions;
    use TemporaryDirectory;

    private Database $database;
    private Planner $planner;
    private ConstantFolding $rule;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        (new Executor($this->database))->run('CREATE TABLE users (id INT PRIMARY KEY, age INT)');

        $this->planner = new Planner($this->database);
        $this->rule = new ConstantFolding();
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    private function filterPredicate(string $sql): mixed
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $optimized = $this->rule->apply($planned->plan, new PlanContext($this->database));

        $filter = self::descend($optimized, Limit::class, Project::class);
        self::assertInstanceOf(Filter::class, $filter);

        return $filter->predicate;
    }

    public function testArithmeticOnTwoLiteralsCollapsesToOneLiteral(): void
    {
        $predicate = $this->filterPredicate('SELECT id FROM users WHERE age > 1 + 2');

        self::assertInstanceOf(BinaryOp::class, $predicate);
        self::assertInstanceOf(Literal::class, $predicate->right);
        self::assertSame(3, $predicate->right->value);
    }

    public function testALogicalCombinationOfLiteralsCollapses(): void
    {
        // Every part of this predicate is a literal once "1 + 1" folds, so
        // the whole AND collapses too - unlike `age > 1 AND TRUE`, where
        // the left side stays a BinaryOp (it references a column) and the
        // AND cannot collapse just because one side is literal.
        $predicate = $this->filterPredicate('SELECT id FROM users WHERE 2 = 1 + 1 AND TRUE');

        self::assertInstanceOf(Literal::class, $predicate);
        self::assertTrue($predicate->value);
    }

    public function testAPlaceholderIsNeverFolded(): void
    {
        $predicate = $this->filterPredicate('SELECT id FROM users WHERE age > 1 + ?');

        self::assertInstanceOf(BinaryOp::class, $predicate);
        self::assertInstanceOf(BinaryOp::class, $predicate->right);
        self::assertInstanceOf(Literal::class, $predicate->right->left);
        self::assertInstanceOf(Placeholder::class, $predicate->right->right);
    }

    public function testAFunctionCallsArgumentsFoldButTheCallItselfNeverCollapses(): void
    {
        $predicate = $this->filterPredicate('SELECT id FROM users WHERE age > ABS(1 - 10)');

        self::assertInstanceOf(BinaryOp::class, $predicate);
        self::assertInstanceOf(FunctionCall::class, $predicate->right);
        self::assertSame('ABS', $predicate->right->name);
        self::assertInstanceOf(Literal::class, $predicate->right->arguments[0]);
        self::assertSame(-9, $predicate->right->arguments[0]->value);
    }
}

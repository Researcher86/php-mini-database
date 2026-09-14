<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql\Planner;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Plan\Sort;
use PhpMiniDatabase\Sql\Planner\Planner;
use PhpMiniDatabase\Tests\Support\PlanAssertions;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `Planner::plan()` against real tables — proving the tree it builds has
 * exactly the shape `Execution\Executor` used to build by hand before
 * Phase 9, for every clause combination that changes that shape.
 */
final class PlannerTest extends TestCase
{
    use PlanAssertions;
    use TemporaryDirectory;

    private Database $database;
    private Planner $planner;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $executor = new Executor($this->database);
        $executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50), age INT)');
        $executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total INT)');

        $this->planner = new Planner($this->database);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    private function plan(string $sql): SelectStatement
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement;
    }

    public function testAPlainSelectPlansAsScanThenProjectThenLimit(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT id, name FROM users'));

        self::assertInstanceOf(Limit::class, $planned->plan);
        $project = self::descend($planned->plan, Limit::class);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Scan::class, $project->source);
        self::assertSame(['id', 'name'], $planned->labels);
    }

    public function testAWhereClauseInsertsAFilterBetweenScanAndProject(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT id FROM users WHERE age > 18'));

        $filter = self::descend($planned->plan, Limit::class, Project::class);
        self::assertInstanceOf(Filter::class, $filter);
        self::assertInstanceOf(Scan::class, $filter->source);
    }

    public function testGroupByPlansAsAggregateRatherThanProject(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT age, COUNT(*) FROM users GROUP BY age'));

        self::assertInstanceOf(Aggregate::class, self::descend($planned->plan, Limit::class));
    }

    public function testOrderBySitsBeforeProjectForAPlainQuery(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT id FROM users ORDER BY age'));

        $sort = self::descend($planned->plan, Limit::class, Project::class);
        self::assertInstanceOf(Sort::class, $sort);
    }

    public function testOrderBySitsAfterAggregateForAGroupedQuery(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT age, COUNT(*) AS n FROM users GROUP BY age ORDER BY n'));

        $aggregate = self::descend($planned->plan, Limit::class, Sort::class);
        self::assertInstanceOf(Aggregate::class, $aggregate);
    }

    public function testDistinctWrapsTheProjection(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT DISTINCT name FROM users'));

        $project = self::descend($planned->plan, Limit::class, Distinct::class);
        self::assertInstanceOf(Project::class, $project);
    }

    public function testEveryPlanEndsInALimitNode(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT id FROM users'));

        self::assertInstanceOf(Limit::class, $planned->plan);
        self::assertNull($planned->plan->limit);
        self::assertSame(0, $planned->plan->offset);
    }

    public function testStarExpandsToOneItemPerColumn(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT * FROM users'));

        self::assertSame(['id', 'name', 'age'], $planned->labels);
    }

    public function testAJoinPlansAsAJoinNodeOverTwoScans(): void
    {
        $planned = $this->planner->plan($this->plan(
            'SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id',
        ));

        $join = self::descend($planned->plan, Limit::class, Project::class);
        self::assertInstanceOf(Join::class, $join);
        self::assertInstanceOf(Scan::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->right);
        self::assertSame('u', $join->left->reference());
        self::assertSame('o', $join->right->reference());
        self::assertNull($join->hash);
    }

    public function testAJoinRejectsSelectStar(): void
    {
        $this->expectException(ExecutionException::class);
        $this->planner->plan($this->plan('SELECT * FROM users u JOIN orders o ON o.user_id = u.id'));
    }

    public function testADerivedTableIsRejected(): void
    {
        $this->expectException(ExecutionException::class);
        $this->planner->plan($this->plan('SELECT * FROM (SELECT id FROM users) AS t'));
    }

    public function testNoScanStartsWithAnIndexChosen(): void
    {
        $planned = $this->planner->plan($this->plan('SELECT id FROM users WHERE id = 1'));

        $scan = self::descend($planned->plan, Limit::class, Project::class, Filter::class);
        self::assertInstanceOf(Scan::class, $scan);
        self::assertNull($scan->index);
    }
}

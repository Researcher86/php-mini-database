<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql\Optimizer;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule\PredicatePushdown;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Planner;
use PhpMiniDatabase\Tests\Support\PlanAssertions;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class PredicatePushdownTest extends TestCase
{
    use PlanAssertions;
    use TemporaryDirectory;

    private Database $database;
    private Planner $planner;
    private PredicatePushdown $rule;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $executor = new Executor($this->database);
        $executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total INT)');

        $this->planner = new Planner($this->database);
        $this->rule = new PredicatePushdown();
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    private function optimizedJoin(string $sql): Join
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $optimized = $this->rule->apply($planned->plan, new PlanContext($this->database));

        // Limit -> Project -> Join (or -> Filter -> Join for a residual).
        $join = self::descend($optimized, Limit::class, Project::class);

        if ($join instanceof Filter) {
            $join = $join->source;
        }

        self::assertInstanceOf(Join::class, $join);

        return $join;
    }

    public function testAConjunctReferencingOnlyOneSideIsPushedOntoThatSidesScan(): void
    {
        $join = $this->optimizedJoin(
            "SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id WHERE u.name = 'Ann'",
        );

        self::assertInstanceOf(Filter::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->left->source);
        self::assertInstanceOf(Scan::class, $join->right);
    }

    public function testAConjunctReferencingBothSidesStaysAsAResidualFilterAboveTheJoin(): void
    {
        $statement = Parser::parseOne(
            'SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id WHERE o.total > u.id',
        );
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $optimized = $this->rule->apply($planned->plan, new PlanContext($this->database));

        $filter = self::descend($optimized, Limit::class, Project::class);
        self::assertInstanceOf(Filter::class, $filter);
        self::assertInstanceOf(Join::class, $filter->source);
        self::assertInstanceOf(Scan::class, $filter->source->left);
        self::assertInstanceOf(Scan::class, $filter->source->right);
    }

    public function testAnUnqualifiedConjunctIsNeverPushed(): void
    {
        $statement = Parser::parseOne(
            "SELECT name, total FROM users u JOIN orders o ON o.user_id = u.id WHERE name = 'Ann'",
        );
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $optimized = $this->rule->apply($planned->plan, new PlanContext($this->database));

        $filter = self::descend($optimized, Limit::class, Project::class);
        self::assertInstanceOf(Filter::class, $filter);
        self::assertInstanceOf(Join::class, $filter->source);
        self::assertInstanceOf(Scan::class, $filter->source->left);
        self::assertInstanceOf(Scan::class, $filter->source->right);
    }

    public function testAConjunctIsNeverPushedOntoALeftJoinsNullableSide(): void
    {
        $join = $this->optimizedJoin(
            "SELECT u.name, o.total FROM users u LEFT JOIN orders o ON o.user_id = u.id WHERE o.total = 50",
        );

        // "o" is the right, nullable side of a LEFT JOIN: pushing this
        // predicate below the join would turn a row with no matching
        // order into "excluded entirely" instead of "kept, then filtered
        // out by this condition" - a different result, not just a
        // different plan.
        self::assertInstanceOf(Scan::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->right);
    }

    public function testAConjunctIsPushedOntoALeftJoinsPreservedSide(): void
    {
        $join = $this->optimizedJoin(
            "SELECT u.name, o.total FROM users u LEFT JOIN orders o ON o.user_id = u.id WHERE u.name = 'Ann'",
        );

        self::assertInstanceOf(Filter::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->right);
    }

    public function testMultipleConjunctsEachPushToTheirOwnSide(): void
    {
        $join = $this->optimizedJoin(
            "SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id WHERE u.name = 'Ann' AND o.total > 10",
        );

        self::assertInstanceOf(Filter::class, $join->left);
        self::assertInstanceOf(Filter::class, $join->right);
    }
}

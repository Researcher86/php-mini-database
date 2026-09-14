<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql\Optimizer;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule\IndexSelection;
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

final class IndexSelectionTest extends TestCase
{
    use PlanAssertions;
    use TemporaryDirectory;

    private Database $database;
    private Planner $planner;
    private IndexSelection $rule;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $executor = new Executor($this->database);
        $executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50), age INT)');
        $executor->run('CREATE INDEX idx_age ON users (age)');
        $executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total INT)');

        $this->planner = new Planner($this->database);
        $this->rule = new IndexSelection();
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    private function scanFor(string $sql): Scan
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $optimized = $this->rule->apply($planned->plan, new PlanContext($this->database));

        // Limit -> Project -> Filter -> Scan.
        $filter = self::descend($optimized, Limit::class, Project::class);
        self::assertInstanceOf(Filter::class, $filter);
        self::assertInstanceOf(Scan::class, $filter->source);

        return $filter->source;
    }

    public function testAnEqualityAgainstAnIndexedColumnChoosesThatIndex(): void
    {
        $scan = $this->scanFor('SELECT id FROM users WHERE age = 30');

        self::assertNotNull($scan->index);
        self::assertSame('idx_age', $scan->index->indexName);
        self::assertSame('eq', $scan->index->operator);
    }

    public function testARangeComparisonChoosesTheIndexToo(): void
    {
        $scan = $this->scanFor('SELECT id FROM users WHERE age > 30');

        self::assertNotNull($scan->index);
        self::assertSame('gt', $scan->index->operator);
    }

    public function testAComparisonAgainstAnUnindexedColumnLeavesTheScanAlone(): void
    {
        $scan = $this->scanFor("SELECT id FROM users WHERE name = 'Ann'");

        self::assertNull($scan->index);
    }

    public function testTheFirstIndexableConjunctOfAnAndChainIsUsed(): void
    {
        $scan = $this->scanFor("SELECT id FROM users WHERE age = 30 AND name = 'Ann'");

        self::assertNotNull($scan->index);
        self::assertSame('idx_age', $scan->index->indexName);
    }

    public function testTheFilterIsKeptEvenOnceAnIndexIsChosen(): void
    {
        $statement = Parser::parseOne('SELECT id FROM users WHERE age = 30');
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $optimized = $this->rule->apply($planned->plan, new PlanContext($this->database));

        // The Filter still wraps the (now indexed) Scan - an IndexScan
        // only narrows what it reads, it is never trusted as the whole
        // answer on its own.
        self::assertInstanceOf(Filter::class, self::descend($optimized, Limit::class, Project::class));
    }

    public function testAConjunctPushedOntoAJoinSideCanStillPickThatSidesIndex(): void
    {
        $sql = 'SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id WHERE u.age = 30';
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(SelectStatement::class, $statement);

        $planned = $this->planner->plan($statement);
        $pushed = (new PredicatePushdown())->apply($planned->plan, new PlanContext($this->database));
        $optimized = $this->rule->apply($pushed, new PlanContext($this->database));

        // Limit -> Project -> Join; the pushed-down Filter(Scan) on the
        // left is exactly what IndexSelection needs to see to attach an
        // index to a join side - something Execution\Executor never did
        // for a JOIN before Phase 9.
        $join = self::descend($optimized, Limit::class, Project::class);
        self::assertInstanceOf(Join::class, $join);
        self::assertInstanceOf(Filter::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->left->source);
        self::assertSame('idx_age', $join->left->source->index?->indexName);
    }
}

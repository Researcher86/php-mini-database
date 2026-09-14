<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql\Optimizer;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule\JoinReordering;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Planner;
use PhpMiniDatabase\Tests\Support\PlanAssertions;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class JoinReorderingTest extends TestCase
{
    use PlanAssertions;
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;
    private Planner $planner;
    private JoinReordering $rule;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total INT)');

        $this->planner = new Planner($this->database);
        $this->rule = new JoinReordering();
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

        // Limit -> Project -> Join.
        $join = self::descend($optimized, Limit::class, Project::class);
        self::assertInstanceOf(Join::class, $join);

        return $join;
    }

    /** Enough short rows in "users" to force it past a single page. */
    private function fillUsersPastOnePage(): void
    {
        $rows = [];

        for ($i = 1; $i <= 800; $i++) {
            $rows[] = "({$i}, 'u{$i}')";
        }

        $this->executor->run('INSERT INTO users (id, name) VALUES ' . implode(', ', $rows));
    }

    public function testAPlainEquiJoinGetsHashKeys(): void
    {
        $join = $this->optimizedJoin('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id');

        self::assertNotNull($join->hash);
        self::assertSame('u.id', $join->hash->leftKey);
        self::assertSame('o.user_id', $join->hash->rightKey);
    }

    public function testANonEqualityConditionNeverGetsHashKeys(): void
    {
        $join = $this->optimizedJoin('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id > u.id');

        self::assertNull($join->hash);
    }

    public function testALeftJoinsSidesAreNeverSwappedEvenWhenTheRightIsLarger(): void
    {
        $this->fillUsersPastOnePage();

        // "users" is now the right side of this LEFT JOIN, and by far the
        // larger table - a plain INNER join in this shape would swap. A
        // LEFT JOIN never does: swapping would turn it into effectively a
        // RIGHT JOIN and change which rows the query returns.
        $join = $this->optimizedJoin('SELECT o.total, u.name FROM orders o LEFT JOIN users u ON o.user_id = u.id');

        self::assertInstanceOf(Scan::class, $join->left);
        self::assertSame('o', $join->left->reference());
        self::assertNull($join->hash);
    }

    public function testTheSmallerSideAlreadyOnTheRightIsLeftUnchanged(): void
    {
        $this->fillUsersPastOnePage();

        // "users" (declared left) is by far the larger table; "orders"
        // (declared right) - HashJoin's hash-built side - is already the
        // smaller one, so there is nothing to swap.
        $join = $this->optimizedJoin('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id');

        self::assertInstanceOf(Scan::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->right);
        self::assertSame('u', $join->left->reference());
        self::assertSame('o', $join->right->reference());
        self::assertNotNull($join->hash);
        self::assertSame('u.id', $join->hash->leftKey);
        self::assertSame('o.user_id', $join->hash->rightKey);
    }

    public function testTheLargerSideDeclaredRightIsSwappedToTheLeft(): void
    {
        $this->fillUsersPastOnePage();

        // "orders" (declared left) is the smaller table; "users" (declared
        // right) is by far the larger one, and HashJoin builds its hash
        // table from $right - the wrong side to put the big table on. The
        // rule swaps them so "orders" ends up as the hash-built side.
        $join = $this->optimizedJoin('SELECT o.total, u.name FROM orders o JOIN users u ON o.user_id = u.id');

        self::assertInstanceOf(Scan::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->right);
        self::assertSame('u', $join->left->reference());
        self::assertSame('o', $join->right->reference());
        self::assertNotNull($join->hash);
        self::assertSame('u.id', $join->hash->leftKey);
        self::assertSame('o.user_id', $join->hash->rightKey);
    }

    public function testANestedJoinIsNotReorderedSinceItsSizeCannotBeEstimatedCheaply(): void
    {
        $this->executor->run('CREATE TABLE payments (id INT PRIMARY KEY, order_id INT, method VARCHAR(20))');

        $join = $this->optimizedJoin(
            'SELECT u.name, o.total, p.method
             FROM users u
             JOIN orders o ON o.user_id = u.id
             JOIN payments p ON p.order_id = o.id',
        );

        // The outer join's left side is itself a Join, not a bare Scan -
        // estimateRows() returns null for it, so this join keeps the
        // FROM clause's own left/right order untouched.
        self::assertInstanceOf(Join::class, $join->left);
        self::assertInstanceOf(Scan::class, $join->right);
        self::assertSame('p', $join->right->reference());
    }
}

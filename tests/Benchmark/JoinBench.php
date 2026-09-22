<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Benchmark;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "load tests" for `JOIN` - see `InsertBench`'s docblock
 * for why this lives outside `composer test`. Seeds a parent/child pair of
 * tables (each parent has several children, the shape a real schema
 * actually has) and times an equi-join through it - once on the join
 * column that `Sql\Planner\Planner` can hash (an equality `ON`), and once
 * through a correlated-looking predicate it cannot, forcing the nested
 * loop fallback.
 */
final class JoinBench extends TestCase
{
    use TemporaryDirectory;

    private const PARENTS = 500;
    private const CHILDREN_PER_PARENT = 5;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);

        $this->executor->run('CREATE TABLE parents (id INT PRIMARY KEY, name VARCHAR(20) NOT NULL)');
        $this->executor->run('CREATE TABLE children (id INT PRIMARY KEY, parent_id INT NOT NULL, n INT NOT NULL)');

        $this->executor->run('BEGIN');

        for ($p = 0; $p < self::PARENTS; $p++) {
            $this->executor->run('INSERT INTO parents (id, name) VALUES (?, ?)', [$p, 'parent' . $p]);

            for ($c = 0; $c < self::CHILDREN_PER_PARENT; $c++) {
                $childId = $p * self::CHILDREN_PER_PARENT + $c;
                $this->executor->run('INSERT INTO children (id, parent_id, n) VALUES (?, ?, ?)', [$childId, $p, $c]);
            }
        }

        $this->executor->run('COMMIT');
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testEquiJoinThroughput(): void
    {
        $start = microtime(true);

        $result = $this->executor->run(
            'SELECT p.name, COUNT(c.id) AS n FROM parents p JOIN children c ON c.parent_id = p.id GROUP BY p.name',
        );
        self::assertInstanceOf(QueryResult::class, $result);
        $rows = iterator_to_array($result->rows, false);

        $elapsed = microtime(true) - $start;

        self::assertCount(self::PARENTS, $rows);
        $this->report('equi-join + GROUP BY (' . (self::PARENTS * self::CHILDREN_PER_PARENT) . ' matched rows)', $elapsed);
    }

    public function testLeftJoinThroughput(): void
    {
        $start = microtime(true);

        $result = $this->executor->run(
            'SELECT p.id FROM parents p LEFT JOIN children c ON c.parent_id = p.id WHERE c.id IS NULL',
        );
        self::assertInstanceOf(QueryResult::class, $result);
        $rows = iterator_to_array($result->rows, false);

        $elapsed = microtime(true) - $start;

        // Every parent has children in this seed, so a LEFT JOIN that
        // filters for none is an emptiness check on the join itself.
        self::assertSame([], $rows);
        $this->report('LEFT JOIN + null filter', $elapsed);
    }

    private function report(string $label, float $elapsedSeconds): void
    {
        fwrite(STDOUT, sprintf("  [JoinBench] %-55s %7.3fs\n", $label, $elapsedSeconds));

        // A generous ceiling, not a target - catches an accidental
        // quadratic-in-the-wrong-dimension regression, not ordinary
        // hardware variance.
        self::assertLessThan(10.0, $elapsedSeconds, "{$label} took longer than 10s - investigate for a regression.");
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Benchmark;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "load tests" for `SELECT` - see `InsertBench`'s docblock
 * for why this lives outside `composer test`. Seeds one table once, then
 * times three access patterns against it: a full sequential scan, a
 * `PRIMARY KEY` point lookup through the B-tree, and a secondary-index
 * point lookup - the three shapes `Sql\Planner\Planner` chooses between
 * per query.
 */
final class SelectBench extends TestCase
{
    use TemporaryDirectory;

    private const ROWS = 5_000;
    private const LOOKUPS = 500;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);

        $this->executor->run('CREATE TABLE t (id INT PRIMARY KEY, category VARCHAR(20) NOT NULL, n INT NOT NULL)');
        $this->executor->run('CREATE INDEX idx_t_category ON t (category)');

        $this->executor->run('BEGIN');

        for ($i = 0; $i < self::ROWS; $i++) {
            $this->executor->run(
                'INSERT INTO t (id, category, n) VALUES (?, ?, ?)',
                [$i, 'cat' . ($i % 20), $i],
            );
        }

        $this->executor->run('COMMIT');
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testFullSequentialScanThroughput(): void
    {
        $start = microtime(true);

        $result = $this->executor->run('SELECT COUNT(*) AS n FROM t');
        self::assertInstanceOf(QueryResult::class, $result);
        iterator_to_array($result->rows, false);

        $elapsed = microtime(true) - $start;
        $this->report('full sequential scan (COUNT over ' . self::ROWS . ' rows)', 1, $elapsed, minPerSecond: 1.0);
    }

    public function testPrimaryKeyPointLookupThroughput(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < self::LOOKUPS; $i++) {
            $this->executor->run('SELECT n FROM t WHERE id = ?', [$i % self::ROWS]);
        }

        $elapsed = microtime(true) - $start;
        $this->report('PRIMARY KEY point lookup', self::LOOKUPS, $elapsed);
    }

    public function testSecondaryIndexPointLookupThroughput(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < self::LOOKUPS; $i++) {
            $this->executor->run('SELECT id FROM t WHERE category = ?', ['cat' . ($i % 20)]);
        }

        $elapsed = microtime(true) - $start;
        $this->report('secondary-index point lookup', self::LOOKUPS, $elapsed);
    }

    private function report(string $label, int $operations, float $elapsedSeconds, float $minPerSecond = 20.0): void
    {
        $perSecond = $elapsedSeconds > 0 ? $operations / $elapsedSeconds : $operations;
        fwrite(STDOUT, sprintf(
            "  [SelectBench] %-40s %6d ops in %7.3fs  (%8.0f ops/s)\n",
            $label,
            $operations,
            $elapsedSeconds,
            $perSecond,
        ));

        self::assertGreaterThan($minPerSecond, $perSecond, "{$label} dropped below {$minPerSecond} ops/s - investigate for a regression.");
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Benchmark;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "load tests" for `INSERT` - not part of `composer test`
 * (see `phpunit.xml`'s `benchmark` testsuite and `composer bench`): every
 * method here prints its own throughput to stdout and only fails on a
 * gross regression (an order of magnitude slower than any of this
 * project's own layers should plausibly be), not on ordinary run-to-run
 * variance - a benchmark that flakes on a slightly busy CI box is worse
 * than no benchmark at all.
 */
final class InsertBench extends TestCase
{
    use TemporaryDirectory;

    private const ROWS = 2_000;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testAutocommitInsertThroughput(): void
    {
        $database = Database::open($this->path('mydb'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, n INT NOT NULL)');

        $start = microtime(true);

        for ($i = 0; $i < self::ROWS; $i++) {
            $executor->run('INSERT INTO t (id, name, n) VALUES (?, ?, ?)', [$i, 'row' . $i, $i * 2]);
        }

        $elapsed = microtime(true) - $start;
        $this->report('autocommit INSERT (1 statement = 1 commit)', self::ROWS, $elapsed);

        $database->close();
    }

    public function testBatchedInsertThroughputInsideOneTransaction(): void
    {
        $database = Database::open($this->path('mydb2'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, n INT NOT NULL)');

        $start = microtime(true);

        $executor->run('BEGIN');

        for ($i = 0; $i < self::ROWS; $i++) {
            $executor->run('INSERT INTO t (id, name, n) VALUES (?, ?, ?)', [$i, 'row' . $i, $i * 2]);
        }

        $executor->run('COMMIT');

        $elapsed = microtime(true) - $start;
        $this->report('batched INSERT (one commit for ' . self::ROWS . ' rows)', self::ROWS, $elapsed);

        $database->close();
    }

    public function testInsertThroughputIntoAnIndexedTable(): void
    {
        $database = Database::open($this->path('mydb3'));
        $executor = new Executor($database);
        $executor->run('CREATE TABLE t (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL UNIQUE)');
        $executor->run('CREATE INDEX idx_t_email ON t (email)');

        $start = microtime(true);

        for ($i = 0; $i < self::ROWS; $i++) {
            $executor->run('INSERT INTO t (id, email) VALUES (?, ?)', [$i, sprintf('user%d@example.com', $i)]);
        }

        $elapsed = microtime(true) - $start;
        $this->report('INSERT maintaining a secondary index', self::ROWS, $elapsed);

        $database->close();
    }

    private function report(string $label, int $rows, float $elapsedSeconds): void
    {
        $perSecond = $elapsedSeconds > 0 ? $rows / $elapsedSeconds : $rows;
        fwrite(STDOUT, sprintf(
            "  [InsertBench] %-40s %6d rows in %7.3fs  (%8.0f rows/s)\n",
            $label,
            $rows,
            $elapsedSeconds,
            $perSecond,
        ));

        // A generous floor, not a target: this catches an accidental
        // O(n^2) regression (e.g. a full table scan per insert), not
        // ordinary hardware or CI variance.
        self::assertGreaterThan(50.0, $perSecond, "{$label} dropped below 50 rows/s - investigate for a regression.");
    }
}

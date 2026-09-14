<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ExecutorGroupByTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);

        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, status VARCHAR(20), age INT)');
        $this->exec(
            "INSERT INTO users (id, status, age) VALUES
                (1, 'active', 30), (2, 'active', 20), (3, 'inactive', 40)",
        );
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql): array
    {
        $result = $this->executor->run($sql);
        self::assertInstanceOf(QueryResult::class, $result);

        return array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false));
    }

    private function exec(string $sql): int
    {
        $result = $this->executor->run($sql);
        self::assertIsInt($result);

        return $result;
    }

    public function testCountStarWithNoGroupByIsOneRow(): void
    {
        self::assertSame([['COUNT' => 3]], $this->query('SELECT COUNT(*) FROM users'));
    }

    public function testCountStarOverAnEmptyResultIsStillOneRowWithZero(): void
    {
        self::assertSame([['COUNT' => 0]], $this->query('SELECT COUNT(*) FROM users WHERE age > 1000'));
    }

    public function testGroupByPartitionsAndCounts(): void
    {
        $rows = $this->query('SELECT status, COUNT(*) AS n FROM users GROUP BY status ORDER BY status');

        self::assertSame([['status' => 'active', 'n' => 2], ['status' => 'inactive', 'n' => 1]], $rows);
    }

    public function testAggregateFunctionsOverAGroup(): void
    {
        $rows = $this->query('SELECT status, SUM(age) AS total, AVG(age) AS avg FROM users GROUP BY status ORDER BY status');

        self::assertSame(
            [['status' => 'active', 'total' => 50, 'avg' => 25], ['status' => 'inactive', 'total' => 40, 'avg' => 40]],
            $rows,
        );
    }

    public function testHavingFiltersGroups(): void
    {
        $rows = $this->query('SELECT status, COUNT(*) AS n FROM users GROUP BY status HAVING COUNT(*) > 1');

        self::assertSame([['status' => 'active', 'n' => 2]], $rows);
    }

    public function testWhereAppliesBeforeGrouping(): void
    {
        $rows = $this->query("SELECT status, COUNT(*) AS n FROM users WHERE age >= 30 GROUP BY status");

        self::assertSame([['status' => 'active', 'n' => 1], ['status' => 'inactive', 'n' => 1]], $this->sortByStatus($rows));
    }

    public function testOrderByOnAGroupedQuerySortsByTheOutputAlias(): void
    {
        $rows = $this->query('SELECT status, COUNT(*) AS n FROM users GROUP BY status ORDER BY n DESC');

        self::assertSame(['active', 'inactive'], array_column($rows, 'status'));
    }

    public function testDistinctDropsDuplicateOutputRows(): void
    {
        $rows = $this->query('SELECT DISTINCT status FROM users ORDER BY status');

        self::assertSame([['status' => 'active'], ['status' => 'inactive']], $rows);
    }

    public function testGroupByWithNoMatchingRowsProducesNoOutput(): void
    {
        self::assertSame([], $this->query('SELECT status, COUNT(*) FROM users WHERE age > 1000 GROUP BY status'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sortByStatus(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $a['status'] <=> $b['status']);

        return $rows;
    }
}

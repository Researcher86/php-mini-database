<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ExecutorJoinTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);

        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total INT)');

        $this->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob'), (3, 'carol')");
        $this->exec('INSERT INTO orders (id, user_id, total) VALUES (100, 1, 50), (101, 1, 30), (102, 2, 20)');
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

    public function testInnerJoinReturnsOnlyMatchedRows(): void
    {
        $rows = $this->query(
            'SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id ORDER BY o.total DESC',
        );

        self::assertSame(
            [['name' => 'alice', 'total' => 50], ['name' => 'alice', 'total' => 30], ['name' => 'bob', 'total' => 20]],
            $rows,
        );
    }

    public function testInnerJoinDropsUsersWithNoOrders(): void
    {
        $names = array_column($this->query('SELECT u.name FROM users u JOIN orders o ON o.user_id = u.id'), 'name');

        self::assertNotContains('carol', $names);
    }

    public function testLeftJoinKeepsUsersWithNoOrders(): void
    {
        $rows = $this->query(
            'SELECT u.name, o.total FROM users u LEFT JOIN orders o ON o.user_id = u.id WHERE u.name = \'carol\'',
        );

        self::assertSame([['name' => 'carol', 'total' => null]], $rows);
    }

    public function testRightJoinKeepsTheRightSidesUnmatchedRows(): void
    {
        $this->exec("INSERT INTO orders (id, user_id, total) VALUES (200, 99, 999)"); // no matching user

        $rows = $this->query('SELECT o.total, u.name FROM users u RIGHT JOIN orders o ON o.user_id = u.id WHERE o.total = 999');

        self::assertSame([['total' => 999, 'name' => null]], $rows);
    }

    public function testJoinConditionThatIsNotAPlainEqualityStillWorksViaNestedLoopJoin(): void
    {
        $rows = $this->query('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id AND o.total > 25');

        self::assertSame([['name' => 'alice', 'total' => 50], ['name' => 'alice', 'total' => 30]], $rows);
    }

    public function testWhereAppliesOnTopOfTheJoin(): void
    {
        $rows = $this->query('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id WHERE o.total > 25');

        self::assertSame([['name' => 'alice', 'total' => 50], ['name' => 'alice', 'total' => 30]], $rows);
    }

    public function testAThreeTableJoinChainsLeftToRight(): void
    {
        $this->executor->run('CREATE TABLE payments (id INT PRIMARY KEY, order_id INT, method VARCHAR(20))');
        $this->exec("INSERT INTO payments (id, order_id, method) VALUES (1000, 100, 'card')");

        $rows = $this->query(
            'SELECT u.name, o.total, p.method
             FROM users u
             JOIN orders o ON o.user_id = u.id
             JOIN payments p ON p.order_id = o.id',
        );

        self::assertSame([['name' => 'alice', 'total' => 50, 'method' => 'card']], $rows);
    }

    public function testSelectStarOverAJoinIsRejected(): void
    {
        $this->expectException(ExecutionException::class);
        $this->query('SELECT * FROM users u JOIN orders o ON o.user_id = u.id');
    }

    public function testAmbiguousUnqualifiedColumnThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->query('SELECT id FROM users u JOIN orders o ON o.user_id = u.id');
    }

    public function testUnqualifiedColumnResolvesWhenItIsUnambiguous(): void
    {
        $rows = $this->query('SELECT name FROM users u JOIN orders o ON o.user_id = u.id WHERE total > 25');

        self::assertSame([['name' => 'alice'], ['name' => 'alice']], $rows);
    }
}

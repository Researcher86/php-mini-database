<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end path: SQL text in, rows or a row count out, against a real
 * on-disk Database. Every other test in Execution/ isolates one piece of
 * this; this file is where they are proven to work together.
 */
final class ExecutorTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
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

    private function createUsers(): void
    {
        $this->executor->run(
            'CREATE TABLE users (
                id INT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                age INT,
                active BOOL DEFAULT TRUE
            )',
        );
    }

    public function testCreateTableThenSelectFromIt(): void
    {
        $this->createUsers();

        self::assertTrue($this->database->hasTable('users'));
        self::assertSame([], $this->query('SELECT * FROM users'));
    }

    public function testCreateTableIfNotExistsIsIdempotent(): void
    {
        $this->createUsers();

        $this->executor->run('CREATE TABLE IF NOT EXISTS users (id INT)');

        self::assertSame(['id', 'name', 'age', 'active'], $this->database->table('users')->columnNames());
    }

    public function testCreateTableWithoutIfNotExistsRejectsADuplicate(): void
    {
        $this->createUsers();

        $this->expectException(SchemaException::class);
        $this->createUsers();
    }

    public function testInsertThenSelectStar(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'alice', 30)");

        self::assertSame(
            [['id' => 1, 'name' => 'alice', 'age' => 30, 'active' => true]],
            $this->query('SELECT * FROM users'),
        );
    }

    public function testInsertAppliesDefaultsAndCasts(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name) VALUES ('7', 'bob')");

        self::assertSame([7, 'bob', null, true], array_values($this->query('SELECT * FROM users')[0]));
    }

    public function testInsertReturnsTheNumberOfRowsInserted(): void
    {
        $this->createUsers();

        self::assertSame(
            2,
            $this->exec("INSERT INTO users (id, name) VALUES (1, 'a'), (2, 'b')"),
        );
    }

    public function testInsertWithoutColumnListUsesTableOrder(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users VALUES (1, 'alice', 30, FALSE)");

        self::assertSame(
            ['id' => 1, 'name' => 'alice', 'age' => 30, 'active' => false],
            $this->query('SELECT * FROM users')[0],
        );
    }

    public function testInsertViolatingNotNullThrows(): void
    {
        $this->createUsers();

        $this->expectException(ConstraintViolationException::class);
        $this->exec('INSERT INTO users (id) VALUES (1)');
    }

    public function testInsertWithAPlaceholder(): void
    {
        $this->createUsers();
        $this->executor->run('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'alice']);

        self::assertSame('alice', $this->query('SELECT * FROM users')[0]['name']);
    }

    public function testSelectWithProjectionAndAlias(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'alice', 30)");

        self::assertSame([['n' => 'alice']], $this->query('SELECT name AS n FROM users'));
    }

    public function testSelectWithWhere(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'alice', 30), (2, 'bob', 17)");

        self::assertSame(['alice'], array_column($this->query('SELECT name FROM users WHERE age >= 18'), 'name'));
    }

    public function testSelectWithOrderByAndLimit(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'c', 3), (2, 'a', 1), (3, 'b', 2)");

        self::assertSame(
            ['a', 'b'],
            array_column($this->query('SELECT name FROM users ORDER BY age ASC LIMIT 2'), 'name'),
        );
    }

    public function testSelectWithOffset(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'a', 1), (2, 'b', 2), (3, 'c', 3)");

        self::assertSame(
            ['b', 'c'],
            array_column($this->query('SELECT name FROM users ORDER BY age ASC OFFSET 1'), 'name'),
        );
    }

    public function testSelectWithNoFromClause(): void
    {
        self::assertSame([['column1' => 3]], $this->query('SELECT 1 + 2'));
    }

    public function testUpdateAppliesToMatchingRowsOnly(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'alice', 30), (2, 'bob', 17)");

        $affected = $this->exec('UPDATE users SET active = FALSE WHERE age < 18');

        self::assertSame(1, $affected);
        self::assertSame(
            ['alice' => true, 'bob' => false],
            array_combine(
                array_column($this->query('SELECT name, active FROM users'), 'name'),
                array_column($this->query('SELECT name, active FROM users'), 'active'),
            ),
        );
    }

    public function testUpdateWithoutWhereAppliesToEveryRow(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'a'), (2, 'b')");

        $affected = $this->exec('UPDATE users SET active = FALSE');

        self::assertSame(2, $affected);
        self::assertSame([false, false], array_column($this->query('SELECT active FROM users'), 'active'));
    }

    public function testUpdateCanReferenceTheOldValueOfTheSameColumn(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'alice', 30)");

        $this->exec('UPDATE users SET age = age + 1');

        self::assertSame(31, $this->query('SELECT age FROM users')[0]['age']);
    }

    /**
     * The classic hazard a naive scan-and-mutate UPDATE would hit: growing
     * a row moves it, and if the move landed on a page the same scan had
     * not yet reached, it would be updated twice. It must not be.
     */
    public function testGrowingARowDuringUpdateDoesNotVisitItTwice(): void
    {
        $this->executor->run('CREATE TABLE t (id INT, note VARCHAR(2000))');
        $this->exec("INSERT INTO t (id, note) VALUES (1, 'x')");

        $affected = $this->exec("UPDATE t SET note = '" . str_repeat('y', 1000) . "'");

        self::assertSame(1, $affected);
        self::assertSame(1, $this->exec('UPDATE t SET id = id')); // still exactly one row
    }

    public function testDeleteWithWhere(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name, age) VALUES (1, 'alice', 30), (2, 'bob', 17)");

        $affected = $this->exec('DELETE FROM users WHERE age < 18');

        self::assertSame(1, $affected);
        self::assertSame(['alice'], array_column($this->query('SELECT name FROM users'), 'name'));
    }

    public function testDeleteWithoutWhereRemovesEverything(): void
    {
        $this->createUsers();
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'a'), (2, 'b')");

        self::assertSame(2, $this->exec('DELETE FROM users'));
        self::assertSame([], $this->query('SELECT * FROM users'));
    }

    public function testDropTable(): void
    {
        $this->createUsers();

        $this->executor->run('DROP TABLE users');

        self::assertFalse($this->database->hasTable('users'));
    }

    public function testDropTableIfExistsIsForgivingOfAMissingTable(): void
    {
        $this->executor->run('DROP TABLE IF EXISTS never_existed');

        self::assertFalse($this->database->hasTable('never_existed'));
    }

    public function testJoinsAreNotSupportedYet(): void
    {
        $this->createUsers();
        $this->executor->run('CREATE TABLE orders (user_id INT)');

        $this->expectException(ExecutionException::class);
        $this->query('SELECT * FROM users JOIN orders ON orders.user_id = users.id');
    }

    public function testGroupByIsNotSupportedYet(): void
    {
        $this->createUsers();

        $this->expectException(ExecutionException::class);
        $this->query('SELECT COUNT(*) FROM users GROUP BY active');
    }

    public function testFullWorkflowEndToEnd(): void
    {
        $this->executor->run(
            'CREATE TABLE products (
                id INT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                price INT NOT NULL,
                in_stock BOOL DEFAULT TRUE
            )',
        );

        $this->exec("INSERT INTO products (id, name, price) VALUES (1, 'Widget', 500), (2, 'Gadget', 1200)");
        $this->exec("UPDATE products SET in_stock = FALSE WHERE id = 2");
        $this->exec('DELETE FROM products WHERE price < 100');

        $rows = $this->query('SELECT name, price FROM products WHERE in_stock = TRUE ORDER BY price DESC');

        self::assertSame([['name' => 'Widget', 'price' => 500]], $rows);
    }
}

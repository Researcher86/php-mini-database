<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `CHECK` and `FOREIGN KEY` end to end through `Executor::run()` — proving
 * `ConstraintEnforcer` and `Executor`'s own cascade handling actually
 * cooperate the way a caller sending SQL text would experience them.
 */
final class ExecutorConstraintTest extends TestCase
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

    private function exec(string $sql): int
    {
        $result = $this->executor->run($sql);
        self::assertIsInt($result);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql): array
    {
        $result = $this->executor->run($sql);
        self::assertInstanceOf(QueryResult::class, $result);

        return array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false));
    }

    // --- CHECK ---

    public function testInsertRejectedByACheckConstraint(): void
    {
        $this->executor->run('CREATE TABLE t (age INT CHECK (age >= 0))');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('INSERT INTO t (age) VALUES (-1)');
    }

    public function testUpdateRejectedByACheckConstraint(): void
    {
        $this->executor->run('CREATE TABLE t (age INT CHECK (age >= 0))');
        $this->exec('INSERT INTO t (age) VALUES (5)');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('UPDATE t SET age = -1');
    }

    public function testAMultiRowInsertFailingItsCheckLeavesNothingBehind(): void
    {
        $this->executor->run('CREATE TABLE t (age INT CHECK (age >= 0))');

        try {
            $this->exec('INSERT INTO t (age) VALUES (1), (2), (-1)');
            self::fail('Expected a ConstraintViolationException.');
        } catch (ConstraintViolationException) {
            // expected
        }

        self::assertSame([], $this->query('SELECT age FROM t'));
    }

    // --- FOREIGN KEY: referencing side ---

    public function testInsertRejectedWhenTheForeignKeyHasNoMatch(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT REFERENCES users (id))');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('INSERT INTO orders (id, user_id) VALUES (1, 1)');
    }

    public function testInsertAllowedWithANullForeignKey(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT REFERENCES users (id))');

        $this->exec('INSERT INTO orders (id, user_id) VALUES (1, NULL)');

        self::assertSame([['id' => 1, 'user_id' => null]], $this->query('SELECT id, user_id FROM orders'));
    }

    public function testUpdateRejectedWhenTheNewForeignKeyHasNoMatch(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT REFERENCES users (id))');
        $this->executor->run('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (1, 1)');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('UPDATE orders SET user_id = 2');
    }

    // --- FOREIGN KEY: referenced side, default (NO ACTION) ---

    private function createUsersAndOrders(string $onDelete = 'NO ACTION', string $onUpdate = 'NO ACTION'): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run(
            "CREATE TABLE orders (
                id INT PRIMARY KEY,
                user_id INT REFERENCES users (id) ON DELETE {$onDelete} ON UPDATE {$onUpdate}
            )",
        );
    }

    public function testDeletingAReferencedParentIsRefusedByDefault(): void
    {
        $this->createUsersAndOrders();
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('DELETE FROM users WHERE id = 1');
    }

    public function testDeletingAnUnreferencedParentIsUnaffected(): void
    {
        $this->createUsersAndOrders();
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO users (id) VALUES (2)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        self::assertSame(1, $this->exec('DELETE FROM users WHERE id = 2'));
        self::assertSame([['id' => 1]], $this->query('SELECT id FROM users'));
    }

    public function testChangingAReferencedKeyIsRefusedByDefault(): void
    {
        $this->createUsersAndOrders();
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('UPDATE users SET id = 2 WHERE id = 1');
    }

    public function testUpdatingAParentRowWithoutTouchingTheReferencedColumnNeverChecksChildren(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->executor->run(
            'CREATE TABLE orders (id INT PRIMARY KEY, user_id INT REFERENCES users (id) ON DELETE RESTRICT)',
        );
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        // "name" is not what "orders" references - this must succeed even
        // though a RESTRICT is declared, because the referenced column
        // (id) never changed.
        self::assertSame(1, $this->exec("UPDATE users SET name = 'Ann2' WHERE id = 1"));
    }

    // --- FOREIGN KEY: CASCADE ---

    public function testDeleteCascadeRemovesChildRows(): void
    {
        $this->createUsersAndOrders(onDelete: 'CASCADE');
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (101, 1)');

        self::assertSame(1, $this->exec('DELETE FROM users WHERE id = 1'));
        self::assertSame([], $this->query('SELECT id FROM orders'));
    }

    public function testDeleteCascadeRecursesThroughAGrandchildTable(): void
    {
        $this->createUsersAndOrders(onDelete: 'CASCADE');
        $this->executor->run(
            'CREATE TABLE payments (
                id INT PRIMARY KEY,
                order_id INT REFERENCES orders (id) ON DELETE CASCADE
            )',
        );
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');
        $this->exec('INSERT INTO payments (id, order_id) VALUES (1000, 100)');

        self::assertSame(1, $this->exec('DELETE FROM users WHERE id = 1'));
        self::assertSame([], $this->query('SELECT id FROM orders'));
        self::assertSame([], $this->query('SELECT id FROM payments'));
    }

    public function testUpdateCascadeChangesTheChildsForeignKeyToo(): void
    {
        $this->createUsersAndOrders(onUpdate: 'CASCADE');
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        self::assertSame(1, $this->exec('UPDATE users SET id = 2 WHERE id = 1'));
        self::assertSame([['id' => 100, 'user_id' => 2]], $this->query('SELECT id, user_id FROM orders'));
    }

    // --- FOREIGN KEY: SET NULL ---

    public function testDeleteSetNullNullsTheChildsForeignKey(): void
    {
        $this->createUsersAndOrders(onDelete: 'SET NULL');
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        self::assertSame(1, $this->exec('DELETE FROM users WHERE id = 1'));
        self::assertSame([['id' => 100, 'user_id' => null]], $this->query('SELECT id, user_id FROM orders'));
    }

    public function testUpdateSetNullNullsTheChildsForeignKey(): void
    {
        $this->createUsersAndOrders(onUpdate: 'SET NULL');
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        self::assertSame(1, $this->exec('UPDATE users SET id = 2 WHERE id = 1'));
        self::assertSame([['id' => 100, 'user_id' => null]], $this->query('SELECT id, user_id FROM orders'));
    }

    public function testSetNullOnANotNullColumnThrowsInstead(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run(
            'CREATE TABLE orders (
                id INT PRIMARY KEY,
                user_id INT NOT NULL REFERENCES users (id) ON DELETE SET NULL
            )',
        );
        $this->exec('INSERT INTO users (id) VALUES (1)');
        $this->exec('INSERT INTO orders (id, user_id) VALUES (100, 1)');

        $this->expectException(ConstraintViolationException::class);
        $this->exec('DELETE FROM users WHERE id = 1');
    }

    // --- Self-reference ---

    public function testASelfReferencingForeignKeyCascadesWithinTheSameTable(): void
    {
        $this->executor->run(
            'CREATE TABLE categories (
                id INT PRIMARY KEY,
                parent_id INT REFERENCES categories (id) ON DELETE CASCADE
            )',
        );
        $this->exec('INSERT INTO categories (id, parent_id) VALUES (1, NULL)');
        $this->exec('INSERT INTO categories (id, parent_id) VALUES (2, 1)');

        self::assertSame(1, $this->exec('DELETE FROM categories WHERE id = 1'));
        self::assertSame([], $this->query('SELECT id FROM categories'));
    }
}

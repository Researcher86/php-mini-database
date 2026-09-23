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
 * `ALTER TABLE ADD COLUMN` / `DROP COLUMN`: the statement that re-encodes
 * every record in a table, because a record carries no schema of its own
 * (see `Storage\RecordSerializer`). What the tests here are really about is
 * everything that has to survive that rewrite — the rows, the indexes that
 * addressed them, and the refusals that keep a column from being dropped
 * out from under something that still names it.
 */
final class ExecutorAlterTableTest extends TestCase
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

    private function createUsers(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, age INT)');
        $this->executor->run("INSERT INTO users (id, name, age) VALUES (1, 'ann', 30), (2, 'bob', 25)");
    }

    // -----------------------------------------------------------------
    // ADD COLUMN
    // -----------------------------------------------------------------

    public function testAddColumnBackfillsExistingRowsFromTheDefault(): void
    {
        $this->createUsers();

        $this->executor->run("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");

        self::assertSame(
            [
                ['id' => 1, 'name' => 'ann', 'age' => 30, 'status' => 'active'],
                ['id' => 2, 'name' => 'bob', 'age' => 25, 'status' => 'active'],
            ],
            $this->query('SELECT * FROM users'),
        );
    }

    public function testAddColumnWithoutADefaultLeavesExistingRowsNull(): void
    {
        $this->createUsers();

        $this->executor->run('ALTER TABLE users ADD COLUMN nickname VARCHAR(20)');

        self::assertSame(
            [['nickname' => null], ['nickname' => null]],
            $this->query('SELECT nickname FROM users'),
        );
    }

    public function testAddedColumnAcceptsValuesFromLaterInserts(): void
    {
        $this->createUsers();
        $this->executor->run("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");

        $this->executor->run("INSERT INTO users (id, name, age, status) VALUES (3, 'cat', 40, 'banned')");

        self::assertSame(
            [['id' => 3, 'status' => 'banned']],
            $this->query('SELECT id, status FROM users WHERE id = 3'),
        );
    }

    /**
     * The deleted row is the point of the setup, not decoration: a rewrite
     * packs the survivors tight, so a table with a hole in it is one whose
     * records actually move. Without it every record would land back at the
     * id it already had and a stale index would look correct.
     */
    public function testAddColumnRebuildsTheIndexesThatAddressedTheOldRecords(): void
    {
        $this->createUsers();
        $this->executor->run("INSERT INTO users (id, name, age) VALUES (3, 'cat', 40)");
        $this->executor->run('CREATE INDEX idx_users_age ON users (age)');
        $this->executor->run('DELETE FROM users WHERE id = 1');

        $this->executor->run("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");

        self::assertSame([['id' => 2, 'status' => 'active']], $this->query('SELECT id, status FROM users WHERE age = 25'));
        self::assertSame([['id' => 3, 'status' => 'active']], $this->query('SELECT id, status FROM users WHERE id = 3'));
    }

    public function testAddColumnSurvivesReopeningTheDatabase(): void
    {
        $this->createUsers();
        $this->executor->run("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");

        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);

        self::assertSame(
            [['id' => 1, 'status' => 'active'], ['id' => 2, 'status' => 'active']],
            $this->query('SELECT id, status FROM users'),
        );
    }

    public function testAddColumnNotNullWithoutADefaultIsRefusedOnATableWithRows(): void
    {
        $this->createUsers();

        try {
            $this->executor->run('ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL');
            self::fail('Expected the added NOT NULL column to be refused.');
        } catch (ConstraintViolationException) {
        }

        self::assertSame(['id', 'name', 'age'], $this->database->table('users')->columnNames());
        self::assertSame(
            [['id' => 1, 'name' => 'ann', 'age' => 30], ['id' => 2, 'name' => 'bob', 'age' => 25]],
            $this->query('SELECT * FROM users'),
        );
    }

    public function testAddColumnNotNullWithoutADefaultIsAllowedOnAnEmptyTable(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');

        $this->executor->run('ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL');

        self::assertSame(['id', 'name', 'status'], $this->database->table('users')->columnNames());
    }

    public function testAddColumnRefusesANameTheTableAlreadyHas(): void
    {
        $this->createUsers();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('already has a column "age"');

        $this->executor->run('ALTER TABLE users ADD COLUMN age INT');
    }

    public function testAddColumnRefusesAnInlinePrimaryKey(): void
    {
        $this->assertAddColumnIsRefused('ALTER TABLE users ADD COLUMN code INT PRIMARY KEY');
    }

    public function testAddColumnRefusesAnInlineUnique(): void
    {
        $this->assertAddColumnIsRefused('ALTER TABLE users ADD COLUMN code INT UNIQUE');
    }

    public function testAddColumnRefusesAnInlineCheck(): void
    {
        $this->assertAddColumnIsRefused('ALTER TABLE users ADD COLUMN code INT CHECK (code > 0)');
    }

    public function testAddColumnRefusesAnInlineForeignKey(): void
    {
        $this->assertAddColumnIsRefused('ALTER TABLE users ADD COLUMN code INT REFERENCES users (id)');
    }

    /**
     * A constraint on an added column would have to hold for every row
     * already in the table, and nothing here checks that yet - so the
     * statement is refused rather than quietly recorded and left false.
     */
    private function assertAddColumnIsRefused(string $sql): void
    {
        $this->createUsers();

        $this->expectException(ExecutionException::class);
        $this->expectExceptionMessage('ADD COLUMN');

        $this->executor->run($sql);
    }

    // -----------------------------------------------------------------
    // DROP COLUMN
    // -----------------------------------------------------------------

    public function testDropColumnRemovesItAndLeavesTheOtherValuesIntact(): void
    {
        $this->createUsers();

        $this->executor->run('ALTER TABLE users DROP COLUMN age');

        self::assertSame(
            [['id' => 1, 'name' => 'ann'], ['id' => 2, 'name' => 'bob']],
            $this->query('SELECT * FROM users'),
        );
    }

    public function testDropColumnRebuildsTheIndexesOnTheColumnsThatRemain(): void
    {
        $this->createUsers();
        $this->executor->run("INSERT INTO users (id, name, age) VALUES (3, 'cat', 40)");
        $this->executor->run('DELETE FROM users WHERE id = 1');

        $this->executor->run('ALTER TABLE users DROP COLUMN age');

        self::assertSame([['name' => 'bob']], $this->query('SELECT name FROM users WHERE id = 2'));
        self::assertSame([['name' => 'cat']], $this->query('SELECT name FROM users WHERE id = 3'));
    }

    public function testDropColumnSurvivesReopeningTheDatabase(): void
    {
        $this->createUsers();
        $this->executor->run('ALTER TABLE users DROP COLUMN age');

        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);

        self::assertSame([['id' => 1, 'name' => 'ann'], ['id' => 2, 'name' => 'bob']], $this->query('SELECT * FROM users'));
    }

    public function testDropColumnRefusesAColumnAnIndexNames(): void
    {
        $this->createUsers();
        $this->executor->run('CREATE INDEX idx_users_age ON users (age)');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('index "idx_users_age" still names it');

        $this->executor->run('ALTER TABLE users DROP COLUMN age');
    }

    public function testDropColumnRefusesAPrimaryKeyColumn(): void
    {
        $this->createUsers();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('constraint "PRIMARY" still names it');

        $this->executor->run('ALTER TABLE users DROP COLUMN id');
    }

    public function testDropColumnRefusesAColumnAnInlineCheckNames(): void
    {
        $this->executor->run('CREATE TABLE items (id INT PRIMARY KEY, price INT CHECK (price >= 0))');

        $this->expectException(SchemaException::class);

        $this->executor->run('ALTER TABLE items DROP COLUMN price');
    }

    public function testDropColumnRefusesAColumnATableLevelCheckMentions(): void
    {
        $this->executor->run('CREATE TABLE items (id INT PRIMARY KEY, price INT, discount INT, CHECK (discount < price))');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('check constraint');

        $this->executor->run('ALTER TABLE items DROP COLUMN discount');
    }

    /**
     * A column another table points at is never left dangling, and needs
     * no check of its own to say so: a foreign key is only accepted
     * against a PRIMARY KEY or UNIQUE column, and that constraint is
     * already reason enough to refuse the drop.
     */
    public function testDropColumnRefusesAColumnAnotherTablesForeignKeyReferences(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(50) UNIQUE)');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, buyer VARCHAR(50) REFERENCES users (email))');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('constraint "uq_users_email" still names it');

        $this->executor->run('ALTER TABLE users DROP COLUMN email');
    }

    public function testDropColumnRefusesTheReferencingSideOfAForeignKeyToo(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(50) UNIQUE)');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, buyer VARCHAR(50) REFERENCES users (email))');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('still names it');

        $this->executor->run('ALTER TABLE orders DROP COLUMN buyer');
    }

    public function testDropColumnRefusesAnUnknownColumn(): void
    {
        $this->createUsers();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('has no column "missing"');

        $this->executor->run('ALTER TABLE users DROP COLUMN missing');
    }

    public function testAlteringAnUnknownTableThrows(): void
    {
        $this->expectException(SchemaException::class);

        $this->executor->run('ALTER TABLE ghosts ADD COLUMN id INT');
    }
}

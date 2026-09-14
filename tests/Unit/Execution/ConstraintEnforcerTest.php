<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\ConstraintEnforcer;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ConstraintEnforcerTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;
    private ConstraintEnforcer $enforcer;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
        $this->enforcer = new ConstraintEnforcer($this->database);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    // --- CHECK ---

    public function testACheckThatEvaluatesToTruePasses(): void
    {
        $this->executor->run('CREATE TABLE t (age INT CHECK (age >= 0))');
        $table = $this->database->table('t');

        $this->enforcer->assertCheckConstraints($table, new Row(['age' => 5]));
        $this->addToAssertionCount(1);
    }

    public function testACheckThatEvaluatesToFalseThrows(): void
    {
        $this->executor->run('CREATE TABLE t (age INT CHECK (age >= 0))');
        $table = $this->database->table('t');

        $this->expectException(ConstraintViolationException::class);
        $this->enforcer->assertCheckConstraints($table, new Row(['age' => -1]));
    }

    public function testACheckThatEvaluatesToNullPasses(): void
    {
        // Standard SQL: a CHECK only ever rejects a row it can PROVE
        // false. A comparison against a NULL column is unknown, not
        // false, and unknown passes.
        $this->executor->run('CREATE TABLE t (age INT CHECK (age >= 0))');
        $table = $this->database->table('t');

        $this->enforcer->assertCheckConstraints($table, new Row(['age' => null]));
        $this->addToAssertionCount(1);
    }

    // --- FOREIGN KEY (referencing side) ---

    public function testAForeignKeyMatchingAnExistingRowPasses(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run('CREATE TABLE orders (user_id INT REFERENCES users (id))');
        $this->executor->run('INSERT INTO users (id) VALUES (1)');

        $orders = $this->database->table('orders');
        $this->enforcer->assertForeignKeysOnWrite($orders, new Row(['user_id' => 1]));
        $this->addToAssertionCount(1);
    }

    public function testAForeignKeyWithNoMatchingRowThrows(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run('CREATE TABLE orders (user_id INT REFERENCES users (id))');

        $orders = $this->database->table('orders');
        $this->expectException(ConstraintViolationException::class);
        $this->enforcer->assertForeignKeysOnWrite($orders, new Row(['user_id' => 1]));
    }

    public function testANullForeignKeyValueIsNeverChecked(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->executor->run('CREATE TABLE orders (user_id INT REFERENCES users (id))');

        $orders = $this->database->table('orders');
        $this->enforcer->assertForeignKeysOnWrite($orders, new Row(['user_id' => null]));
        $this->addToAssertionCount(1);
    }

    public function testACompositeForeignKeyIsCheckedByAFullScanOfTheParent(): void
    {
        // Neither PRIMARY KEY column is single-column here, so there is no
        // BTreeIndex to look the key up in - ConstraintEnforcer falls back
        // to scanning "regions" directly.
        $this->executor->run('CREATE TABLE regions (country VARCHAR(2), code INT, PRIMARY KEY (country, code))');
        $this->executor->run(
            'CREATE TABLE offices (
                country VARCHAR(2), code INT,
                FOREIGN KEY (country, code) REFERENCES regions (country, code)
            )',
        );
        $this->executor->run("INSERT INTO regions (country, code) VALUES ('US', 1)");

        $offices = $this->database->table('offices');
        $this->enforcer->assertForeignKeysOnWrite($offices, new Row(['country' => 'US', 'code' => 1]));

        $this->expectException(ConstraintViolationException::class);
        $this->enforcer->assertForeignKeysOnWrite($offices, new Row(['country' => 'US', 'code' => 2]));
    }
}

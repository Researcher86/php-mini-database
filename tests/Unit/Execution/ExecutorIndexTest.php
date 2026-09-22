<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for Phase 6: CREATE/DROP INDEX, uniqueness enforced
 * through PRIMARY KEY/UNIQUE-backed indexes, and SELECT actually taking the
 * index path (proven by result correctness, not by inspecting which
 * operator ran - see IndexScanTest for that).
 */
final class ExecutorIndexTest extends TestCase
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

    public function testPrimaryKeyIsBackedByAUniqueIndexAtCreateTime(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');

        self::assertTrue($this->database->table('users')->hasIndex('PRIMARY'));
    }

    public function testInsertingADuplicatePrimaryKeyIsRejected(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'a')");

        $this->expectException(ConstraintViolationException::class);
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'b')");
    }

    public function testAFailedInsertNeverTouchesTheHeap(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'a')");

        try {
            $this->exec("INSERT INTO users (id, name) VALUES (1, 'b')");
        } catch (ConstraintViolationException) {
            // expected
        }

        self::assertSame([['id' => 1, 'name' => 'a']], $this->query('SELECT * FROM users'));
    }

    public function testInsertingADuplicateUniqueColumnIsRejected(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE)');
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");

        $this->expectException(ConstraintViolationException::class);
        $this->exec("INSERT INTO users (id, email) VALUES (2, 'a@x.com')");
    }

    public function testUpdatingARowToADuplicateUniqueValueIsRejected(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE)');
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com'), (2, 'b@x.com')");

        $this->expectException(ConstraintViolationException::class);
        $this->exec("UPDATE users SET email = 'a@x.com' WHERE id = 2");
    }

    public function testUpdatingARowKeepingItsOwnUniqueValueIsAllowed(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE, active BOOL DEFAULT TRUE)');
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");

        $affected = $this->exec("UPDATE users SET email = 'a@x.com', active = FALSE WHERE id = 1");

        self::assertSame(1, $affected);
    }

    public function testAFailedUpdateLeavesEveryRowUntouched(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE)');
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com'), (2, 'b@x.com')");

        try {
            $this->exec("UPDATE users SET email = 'a@x.com'"); // no WHERE: both rows would collide
        } catch (ConstraintViolationException) {
            // expected
        }

        self::assertSame(
            ['a@x.com', 'b@x.com'],
            array_column($this->query('SELECT email FROM users ORDER BY id'), 'email'),
        );
    }

    public function testDeletingARowFreesItsUniqueValueForReuse(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE)');
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");
        $this->exec('DELETE FROM users WHERE id = 1');

        $this->exec("INSERT INTO users (id, email) VALUES (2, 'a@x.com')");

        self::assertSame(['a@x.com'], array_column($this->query('SELECT email FROM users'), 'email'));
    }

    public function testCreateIndexOnAPlainColumnAllowsDuplicates(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, status VARCHAR(20))');
        $this->executor->run('CREATE INDEX idx_status ON users (status)');
        $this->exec("INSERT INTO users (id, status) VALUES (1, 'active'), (2, 'active')");

        self::assertTrue($this->database->table('users')->hasIndex('idx_status'));
        self::assertCount(2, $this->query("SELECT * FROM users WHERE status = 'active'"));
    }

    public function testCreateIndexBackfillsExistingRows(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, status VARCHAR(20))');
        $this->exec("INSERT INTO users (id, status) VALUES (1, 'active'), (2, 'inactive')");

        $this->executor->run('CREATE INDEX idx_status ON users (status)');

        self::assertCount(1, $this->query("SELECT * FROM users WHERE status = 'active'"));
    }

    public function testCreateUniqueIndexRejectsExistingDuplicatesGoingForward(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, status VARCHAR(20))');
        $this->executor->run('CREATE UNIQUE INDEX idx_status ON users (status)');
        $this->exec("INSERT INTO users (id, status) VALUES (1, 'active')");

        $this->expectException(ConstraintViolationException::class);
        $this->exec("INSERT INTO users (id, status) VALUES (2, 'active')");
    }

    public function testDropIndexRemovesTheDeclarationAndStopsEnforcingUniqueness(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE)');

        $this->executor->run('DROP INDEX uq_users_email ON users');

        self::assertFalse($this->database->table('users')->hasIndex('uq_users_email'));
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");
        $this->exec("INSERT INTO users (id, email) VALUES (2, 'a@x.com')"); // no longer rejected

        self::assertCount(2, $this->query('SELECT * FROM users'));
    }

    public function testARecreatedIndexOfTheSameNameStartsEmptyNotStale(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, status VARCHAR(20))');
        $this->executor->run('CREATE INDEX idx_status ON users (status)');
        $this->exec("INSERT INTO users (id, status) VALUES (1, 'active')");

        $this->executor->run('DROP INDEX idx_status ON users');
        $this->exec("INSERT INTO users (id, status) VALUES (2, 'inactive')");
        $this->executor->run('CREATE INDEX idx_status ON users (status)');

        // A stale reused file would still "remember" id 1 under 'active'
        // from before the drop; a fresh one only has what was backfilled.
        self::assertCount(1, $this->query("SELECT * FROM users WHERE status = 'inactive'"));
    }

    public function testSelectByPrimaryKeyUsesTheIndexAndFindsTheRightRow(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'a'), (2, 'b'), (3, 'c')");

        self::assertSame([['id' => 2, 'name' => 'b']], $this->query('SELECT * FROM users WHERE id = 2'));
    }

    public function testSelectWithAnIndexedRangeCondition(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'a'), (2, 'b'), (3, 'c')");

        self::assertSame(
            ['b', 'c'],
            array_column($this->query('SELECT name FROM users WHERE id >= 2 ORDER BY id'), 'name'),
        );
    }

    public function testSelectWithAnIndexedColumnPlusAnotherConditionAppliesBoth(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, active BOOL)');
        $this->exec('INSERT INTO users (id, active) VALUES (1, TRUE), (2, FALSE)');

        // id = 2 would use the index; active = TRUE must still be checked.
        self::assertSame([], $this->query('SELECT * FROM users WHERE id = 2 AND active = TRUE'));
    }

    public function testSelectOnANonIndexedColumnStillWorksViaSeqScan(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO users (id, name) VALUES (1, 'alice')");

        self::assertSame([['id' => 1, 'name' => 'alice']], $this->query("SELECT * FROM users WHERE name = 'alice'"));
    }

    /**
     * A `CREATE UNIQUE INDEX` that the existing rows refuse must leave no
     * index at all. Publishing the declaration first and filling the file
     * afterwards left one the catalog named and the planner trusted while
     * it held only the rows the build reached before failing — so a later
     * `SELECT` on that column answered from it, silently missing the rest.
     */
    public function testAFailedUniqueIndexBuildLeavesNoIndexBehind(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(50))');
        $this->exec("INSERT INTO users (id, email) VALUES (1, 'a@x.com'), (2, 'a@x.com')");

        try {
            $this->executor->run('CREATE UNIQUE INDEX idx_email ON users (email)');
            self::fail('Expected the duplicate value to be refused.');
        } catch (ConstraintViolationException) {
            // Expected.
        }

        self::assertSame(
            ['PRIMARY'],
            array_map(static fn ($definition): string => $definition->name, $this->database->table('users')->indexes()),
        );

        // Answered by a scan now that no index claims to cover the column,
        // and so complete - both rows, not just the one the build reached.
        self::assertSame(
            [['id' => 1, 'email' => 'a@x.com'], ['id' => 2, 'email' => 'a@x.com']],
            $this->query("SELECT * FROM users WHERE email = 'a@x.com'"),
        );
    }

    /**
     * A second `CREATE INDEX` under a name already taken must be refused
     * before it touches anything. The build writes a temporary file and
     * renames it into place, and that rename lands on the *existing*
     * index's path — so a name check left until the catalog update would
     * have destroyed a working index on its way to reporting the error,
     * leaving the schema describing one column and the file holding
     * another's keys. Nothing can tell those apart afterwards: the planner
     * trusts the schema and answers from the wrong tree.
     */
    public function testCreatingAnIndexUnderATakenNameLeavesTheExistingOneIntact(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50), email VARCHAR(50))');
        $this->exec("INSERT INTO users (id, name, email) VALUES (1, 'Ann', 'a@x.com'), (2, 'Bea', 'b@x.com')");
        $this->executor->run('CREATE INDEX idx ON users (name)');

        try {
            $this->executor->run('CREATE INDEX idx ON users (email)');
            self::fail('Expected the duplicate index name to be refused.');
        } catch (SchemaException) {
            // Expected.
        }

        // Checked after a reopen: in this process the old index object is
        // still cached and would keep answering from the file it opened,
        // whatever replaced it on disk.
        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $reopened = new Executor($this->database);

        $result = $reopened->run("SELECT id FROM users WHERE name = 'Ann'");
        self::assertInstanceOf(QueryResult::class, $result);
        self::assertSame(
            [['id' => 1]],
            array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false)),
        );
    }

    /**
     * Dropping a table has to drop the open handles onto its files too. An
     * open handle to an unlinked file keeps working on Unix, so a cached
     * one would quietly serve the next table of the same name — writing
     * its rows into a file that vanishes with the process.
     */
    public function testATableRecreatedUnderTheSameNameGetsItsOwnFiles(): void
    {
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->exec('INSERT INTO users (id) VALUES (1)');

        $this->executor->run('DROP TABLE users');
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->exec('INSERT INTO users (id) VALUES (2)');

        self::assertSame([['id' => 2]], $this->query('SELECT * FROM users'));

        // And the row is really on disk, not only in a handle this process
        // happens to still hold.
        $this->database->close();
        $this->database = Database::open($this->path('mydb'));
        $reopened = new Executor($this->database);

        $result = $reopened->run('SELECT * FROM users');
        self::assertInstanceOf(QueryResult::class, $result);
        self::assertSame(
            [['id' => 2]],
            array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false)),
        );
    }
}

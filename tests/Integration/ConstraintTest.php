<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "integration tests" for constraints: where
 * `ExecutorConstraintTest` proves each constraint kind in isolation, this
 * proves them *interacting* - a `CASCADE` that itself runs into a `CHECK`,
 * a batch insert that fails partway through, `NOT NULL`/`UNIQUE`/`CHECK`/
 * foreign keys all guarding the same table at once - the shapes a single
 * feature's own tests never combine.
 */
final class ConstraintTest extends TestCase
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

    private function exec(string $sql): void
    {
        $this->executor->run($sql);
    }

    public function testEveryConstraintKindGuardsTheSameTableTogether(): void
    {
        $this->exec(
            'CREATE TABLE accounts ('
            . 'id INT PRIMARY KEY, '
            . "email VARCHAR(100) NOT NULL UNIQUE, "
            . 'balance INT NOT NULL CHECK (balance >= 0)'
            . ')',
        );
        $this->exec("INSERT INTO accounts (id, email, balance) VALUES (1, 'a@x.com', 100)");

        $this->assertRejected(
            "INSERT INTO accounts (id, email, balance) VALUES (2, 'a@x.com', 50)",
            'a duplicate email should violate UNIQUE',
        );
        $this->assertRejected(
            "INSERT INTO accounts (id, email, balance) VALUES (1, 'b@x.com', 50)",
            'a duplicate id should violate PRIMARY KEY',
        );
        $this->assertRejected(
            'INSERT INTO accounts (id, email, balance) VALUES (2, NULL, 50)',
            'a null email should violate NOT NULL',
        );
        $this->assertRejected(
            "INSERT INTO accounts (id, email, balance) VALUES (2, 'b@x.com', -10)",
            'a negative balance should violate CHECK',
        );

        // None of the rejected statements should have left anything behind.
        self::assertSame([['id' => 1, 'email' => 'a@x.com', 'balance' => 100]], $this->query('SELECT * FROM accounts'));
    }

    public function testCascadingDeleteCanItselfBeBlockedByAForeignKeyOneLevelDown(): void
    {
        $this->exec('CREATE TABLE authors (id INT PRIMARY KEY)');
        $this->exec('CREATE TABLE posts (id INT PRIMARY KEY, author_id INT NOT NULL REFERENCES authors (id) ON DELETE CASCADE)');
        $this->exec('CREATE TABLE comments (id INT PRIMARY KEY, post_id INT NOT NULL REFERENCES posts (id))');

        $this->exec('INSERT INTO authors (id) VALUES (1)');
        $this->exec('INSERT INTO posts (id, author_id) VALUES (1, 1)');
        $this->exec('INSERT INTO comments (id, post_id) VALUES (1, 1)');

        // Deleting the author would cascade into posts, but a comment still
        // references that post with no ON DELETE action of its own - the
        // delete must be rejected, and the cascade into posts must not have
        // partially applied.
        $this->assertRejected('DELETE FROM authors WHERE id = 1', 'a comment still references the cascaded post');

        self::assertSame([['id' => 1]], $this->query('SELECT * FROM authors'));
        self::assertSame([['id' => 1, 'author_id' => 1]], $this->query('SELECT * FROM posts'));
        self::assertSame([['id' => 1, 'post_id' => 1]], $this->query('SELECT * FROM comments'));
    }

    public function testSetNullOnDeleteClearsTheForeignKeyRatherThanRemovingTheRow(): void
    {
        $this->exec('CREATE TABLE authors (id INT PRIMARY KEY)');
        $this->exec('CREATE TABLE posts (id INT PRIMARY KEY, author_id INT REFERENCES authors (id) ON DELETE SET NULL)');

        $this->exec('INSERT INTO authors (id) VALUES (1)');
        $this->exec('INSERT INTO posts (id, author_id) VALUES (1, 1)');

        $this->exec('DELETE FROM authors WHERE id = 1');

        self::assertSame([['id' => 1, 'author_id' => null]], $this->query('SELECT * FROM posts'));
    }

    public function testAMultiRowInsertThatViolatesAConstraintPartwayThroughLeavesNoRowsCommitted(): void
    {
        $this->exec('CREATE TABLE t (id INT PRIMARY KEY)');

        $this->assertRejected(
            'INSERT INTO t (id) VALUES (1), (2), (1)',
            "the third row duplicates the first row's primary key",
        );

        self::assertSame([], $this->query('SELECT * FROM t'));
    }

    private function assertRejected(string $sql, string $becauseDescription): void
    {
        try {
            $this->exec($sql);
            self::fail(sprintf('Expected a ConstraintViolationException because %s.', $becauseDescription));
        } catch (ConstraintViolationException) {
            // Expected.
        }
    }
}

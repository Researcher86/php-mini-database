<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Backup;

use PhpMiniDatabase\Backup\Restorer;
use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class RestorerTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;

    private Executor $executor;

    private Restorer $restorer;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
        $this->restorer = new Restorer($this->executor);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testRestoresSchemaAndData(): void
    {
        $count = $this->restorer->restore(
            "CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50));\n"
            . "INSERT INTO users (id, name) VALUES (1, 'Ann'), (2, 'Bob');\n",
        );

        self::assertSame(2, $count);

        $result = $this->executor->run('SELECT id, name FROM users ORDER BY id');
        self::assertInstanceOf(QueryResult::class, $result);
        $rows = iterator_to_array($result->rows, false);
        self::assertCount(2, $rows);
        self::assertSame(['id' => 1, 'name' => 'Ann'], $rows[0]->toArray());
    }

    public function testSkipsCommentsAndBlankLines(): void
    {
        $count = $this->restorer->restore(
            "-- a header comment\n\nCREATE TABLE t (id INT PRIMARY KEY);\n\n-- trailing\n",
        );

        self::assertSame(1, $count);
        self::assertTrue($this->database->hasTable('t'));
    }

    public function testStopsAtTheFirstFailingStatementAndPropagatesTheException(): void
    {
        $this->expectException(ConstraintViolationException::class);

        $this->restorer->restore(
            "CREATE TABLE t (id INT PRIMARY KEY);\n"
            . "INSERT INTO t (id) VALUES (1);\n"
            . "INSERT INTO t (id) VALUES (1);\n" // duplicate primary key
            . "INSERT INTO t (id) VALUES (2);\n", // never reached
        );
    }

    public function testEmptyInputRestoresNothing(): void
    {
        self::assertSame(0, $this->restorer->restore(''));
    }
}

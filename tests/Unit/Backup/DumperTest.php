<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Backup;

use PhpMiniDatabase\Backup\Dumper;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\MemoryStream;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class DumperTest extends TestCase
{
    use TemporaryDirectory;
    use MemoryStream;

    private Database $database;

    private Executor $executor;

    private Dumper $dumper;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
        $this->dumper = new Dumper($this->database, $this->executor);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    private function exec(string $sql): void
    {
        $this->executor->run($sql);
    }

    /** @param list<string>|null $tables */
    private function dump(?array $tables = null): string
    {
        $stream = $this->memoryStream();
        $this->dumper->dump($stream, $tables);
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function testDumpsAColumnDefinitionWithTypeNotNullAndDefault(): void
    {
        $this->exec('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, age INT DEFAULT 0)');

        $sql = $this->dump();

        self::assertStringContainsString('id INT NOT NULL', $sql);
        self::assertStringContainsString('name VARCHAR(50) NOT NULL', $sql);
        self::assertStringContainsString('age INT DEFAULT 0', $sql);
        self::assertStringContainsString('PRIMARY KEY (id)', $sql);
    }

    public function testDumpsUniqueCheckAndForeignKeyConstraints(): void
    {
        $this->exec('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE, age INT CHECK (age >= 0))');
        $this->exec('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, '
            . 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)');

        $sql = $this->dump();

        self::assertStringContainsString('UNIQUE (email)', $sql);
        self::assertStringContainsString('CHECK (age >= 0)', $sql);
        self::assertStringContainsString('FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE', $sql);
    }

    public function testDumpsRowsAsInsertStatements(): void
    {
        $this->exec('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO t (id, name) VALUES (1, 'Ann'), (2, 'Bob')");

        $sql = $this->dump();

        self::assertStringContainsString("INSERT INTO t (id, name) VALUES (1, 'Ann');", $sql);
        self::assertStringContainsString("INSERT INTO t (id, name) VALUES (2, 'Bob');", $sql);
    }

    public function testEscapesAQuoteInAStringValue(): void
    {
        $this->exec('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->exec("INSERT INTO t (id, name) VALUES (1, 'O''Brien')");

        $sql = $this->dump();

        self::assertStringContainsString("VALUES (1, 'O''Brien');", $sql);
    }

    public function testDumpsAGenuineSecondaryIndexButNotTheAutomaticOnes(): void
    {
        $this->exec('CREATE TABLE t (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE, age INT)');
        $this->exec('CREATE INDEX idx_t_age ON t (age)');

        $sql = $this->dump();

        self::assertStringContainsString('CREATE INDEX idx_t_age ON t (age);', $sql);
        // The PK and UNIQUE constraint each get an automatic index of the
        // same name as the constraint - re-emitting those as CREATE INDEX
        // would try to create the same index twice on restore.
        self::assertStringNotContainsString('CREATE INDEX PRIMARY', $sql);
        self::assertStringNotContainsString('CREATE UNIQUE INDEX uq_t_email', $sql);
    }

    public function testDefaultDumpsEveryTableInDependencyOrder(): void
    {
        // A foreign key's parent must already exist, so users is created
        // first - but Database::tableNames() lists tables alphabetically
        // ("orders" before "users"), the wrong order for a dump a Restorer
        // could actually replay. The dump has to correct for that itself.
        $this->exec('CREATE TABLE users (id INT PRIMARY KEY)');
        $this->exec('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, '
            . 'FOREIGN KEY (user_id) REFERENCES users (id))');
        self::assertSame(['orders', 'users'], $this->database->tableNames());

        $sql = $this->dump();

        self::assertLessThan(
            strpos($sql, 'CREATE TABLE orders'),
            strpos($sql, 'CREATE TABLE users'),
            'users must be created before orders, which references it',
        );
    }

    public function testAnExplicitTableListDumpsOnlyThoseTables(): void
    {
        $this->exec('CREATE TABLE a (id INT PRIMARY KEY)');
        $this->exec('CREATE TABLE b (id INT PRIMARY KEY)');

        $sql = $this->dump(['b']);

        self::assertStringNotContainsString('CREATE TABLE a', $sql);
        self::assertStringContainsString('CREATE TABLE b', $sql);
    }

    public function testATableWithNoRowsProducesNoInsertStatements(): void
    {
        $this->exec('CREATE TABLE t (id INT PRIMARY KEY)');

        $sql = $this->dump();

        self::assertStringNotContainsString('INSERT INTO', $sql);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Database is a thin façade over Catalog; these tests only confirm the
 * delegation, since Catalog's own tests already cover the behaviour.
 */
final class DatabaseTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testOpenCreatesAUsableDatabase(): void
    {
        $db = Database::open($this->path('mydb'));

        self::assertSame([], $db->tableNames());
    }

    public function testTablesCanBeCreatedFoundAndDropped(): void
    {
        $db = Database::open($this->path('mydb'));
        $table = new Table('users', [new Column('id', new IntType(), notNull: true)], [new PrimaryKey(['id'])]);

        $db->createTable($table);

        self::assertTrue($db->hasTable('users'));
        self::assertSame(['users'], $db->tableNames());
        self::assertSame(['id'], $db->table('users')->columnNames());

        $db->dropTable('users');

        self::assertFalse($db->hasTable('users'));
    }

    public function testATableSurvivesReopeningTheDatabase(): void
    {
        Database::open($this->path('mydb'))->createTable(
            new Table('users', [new Column('id', new IntType())]),
        );

        $reopened = Database::open($this->path('mydb'));

        self::assertTrue($reopened->hasTable('users'));
    }

    public function testReadingAnUnknownTableThrows(): void
    {
        $this->expectException(SchemaException::class);
        Database::open($this->path('mydb'))->table('missing');
    }

    public function testHeapFileIsOpenedOnFirstUseAndReusedAfter(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));

        self::assertSame($db->heapFile('users'), $db->heapFile('users'));

        $db->close();
    }

    public function testHeapFileForAnUnknownTableThrows(): void
    {
        $this->expectException(SchemaException::class);
        Database::open($this->path('mydb'))->heapFile('missing');
    }

    public function testDataWrittenThroughHeapFileSurvivesReopening(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));
        $table = $db->table('users');
        $id = $db->heapFile('users')->insert($table->serializeRow(new Row(['id' => 1])));
        $db->close();

        $reopened = Database::open($this->path('mydb'));

        self::assertSame(['id' => 1], $reopened->table('users')->deserializeRow($reopened->heapFile('users')->read($id))->toArray());
    }
}

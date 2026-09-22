<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Storage\RecordId;
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

        $bytes = $reopened->heapFile('users')->read($id);
        self::assertNotNull($bytes);
        self::assertSame(['id' => 1], $reopened->table('users')->deserializeRow($bytes)->toArray());
    }

    public function testAddIndexMakesItAvailableThroughIndex(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));

        $db->addIndex('users', new IndexDefinition('idx_users_id', ['id']));

        self::assertTrue($db->table('users')->hasIndex('idx_users_id'));

        $index = $db->index('users', 'idx_users_id');
        $index->insert(1, new RecordId(0, 0));

        self::assertCount(1, iterator_to_array($index->search(1), false));

        $db->close();
    }

    public function testIndexIsCachedAcrossCalls(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));
        $db->addIndex('users', new IndexDefinition('idx_users_id', ['id']));

        self::assertSame($db->index('users', 'idx_users_id'), $db->index('users', 'idx_users_id'));

        $db->close();
    }

    public function testIndexForAnUndeclaredNameThrows(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));

        $this->expectException(SchemaException::class);
        $db->index('users', 'missing');
    }

    public function testDropIndexRemovesTheDeclarationAndUncachesTheOpenFile(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));
        $db->addIndex('users', new IndexDefinition('idx_users_id', ['id']));
        $db->index('users', 'idx_users_id');

        $db->dropIndex('users', 'idx_users_id');

        self::assertFalse($db->table('users')->hasIndex('idx_users_id'));

        $this->expectException(SchemaException::class);
        $db->index('users', 'idx_users_id');
    }

    public function testAnIndexSurvivesReopeningTheDatabase(): void
    {
        $db = Database::open($this->path('mydb'));
        $db->createTable(new Table('users', [new Column('id', new IntType())]));
        $db->addIndex('users', new IndexDefinition('idx_users_id', ['id']));
        $db->index('users', 'idx_users_id')->insert(1, new RecordId(0, 0));
        $db->close();

        $reopened = Database::open($this->path('mydb'));

        self::assertCount(1, iterator_to_array($reopened->index('users', 'idx_users_id')->search(1), false));
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\IndexMaintainer;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class IndexMaintainerTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private IndexMaintainer $maintainer;
    private Table $table;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->maintainer = new IndexMaintainer($this->database);

        $this->table = new Table('users', [new Column('id', new IntType()), new Column('email', new IntType())]);
        $this->database->createTable($this->table);
        $this->database->addIndex('users', new IndexDefinition('uq_email', ['email'], unique: true));
        $this->table = $this->database->table('users');
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testAfterInsertMakesTheRowFindableByTheIndex(): void
    {
        $row = new Row(['id' => 1, 'email' => 42]);
        $id = new RecordId(0, 0);

        $this->maintainer->afterInsert($this->table, $row, $id);

        $found = iterator_to_array($this->database->index('users', 'uq_email')->search(42), false);
        self::assertCount(1, $found);
        self::assertTrue($found[0]->equals($id));
    }

    public function testAssertUniqueForInsertPassesWhenNoConflict(): void
    {
        $this->maintainer->assertUniqueForInsert($this->table, new Row(['id' => 1, 'email' => 42]));

        $this->addToAssertionCount(1); // did not throw
    }

    public function testAssertUniqueForInsertThrowsOnAConflict(): void
    {
        $this->maintainer->afterInsert($this->table, new Row(['id' => 1, 'email' => 42]), new RecordId(0, 0));

        $this->expectException(ConstraintViolationException::class);
        $this->maintainer->assertUniqueForInsert($this->table, new Row(['id' => 2, 'email' => 42]));
    }

    public function testAssertUniqueForUpdateExcludesTheRowsOwnEntry(): void
    {
        $id = new RecordId(0, 0);
        $this->maintainer->afterInsert($this->table, new Row(['id' => 1, 'email' => 42]), $id);

        // Keeping the same value on the same row is not a conflict.
        $this->maintainer->assertUniqueForUpdate($this->table, new Row(['id' => 1, 'email' => 42]), $id);

        $this->addToAssertionCount(1);
    }

    public function testAssertUniqueForUpdateStillCatchesAConflictWithAnotherRow(): void
    {
        $this->maintainer->afterInsert($this->table, new Row(['id' => 1, 'email' => 42]), new RecordId(0, 0));
        $otherId = new RecordId(0, 1);
        $this->maintainer->afterInsert($this->table, new Row(['id' => 2, 'email' => 99]), $otherId);

        $this->expectException(ConstraintViolationException::class);
        $this->maintainer->assertUniqueForUpdate($this->table, new Row(['id' => 2, 'email' => 42]), $otherId);
    }

    public function testAfterDeleteRemovesTheEntry(): void
    {
        $id = new RecordId(0, 0);
        $this->maintainer->afterInsert($this->table, new Row(['id' => 1, 'email' => 42]), $id);

        $this->maintainer->afterDelete($this->table, new Row(['id' => 1, 'email' => 42]), $id);

        self::assertSame([], iterator_to_array($this->database->index('users', 'uq_email')->search(42), false));
    }

    public function testANonUniqueIndexNeverBlocksAnInsert(): void
    {
        $this->database->addIndex('users', new IndexDefinition('idx_id', ['id']));
        $table = $this->database->table('users');

        $this->maintainer->afterInsert($table, new Row(['id' => 1, 'email' => 1]), new RecordId(0, 0));
        $this->maintainer->assertUniqueForInsert($table, new Row(['id' => 1, 'email' => 2]));

        $this->addToAssertionCount(1);
    }
}

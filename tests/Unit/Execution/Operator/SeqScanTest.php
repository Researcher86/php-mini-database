<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Operator\SeqScan;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Storage\HeapFile;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * SeqScan's keys are RecordId objects, not ints or strings - PHP arrays
 * cannot be keyed by an object, so every test here iterates with foreach
 * (or iterator_to_array's $preserve_keys = false) rather than letting
 * iterator_to_array build a keyed array directly.
 */
final class SeqScanTest extends TestCase
{
    use TemporaryDirectory;

    private Table $table;
    private HeapFile $heap;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->table = new Table('users', [new Column('id', new IntType()), new Column('name', new VarcharType(50))]);
        $this->heap = HeapFile::open($this->path('heap.dat'));
    }

    protected function tearDown(): void
    {
        $this->heap->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testYieldsEveryRowDeserialized(): void
    {
        $this->heap->insert($this->table->serializeRow(new Row(['id' => 1, 'name' => 'alice'])));
        $this->heap->insert($this->table->serializeRow(new Row(['id' => 2, 'name' => 'bob'])));

        $rows = iterator_to_array(new SeqScan($this->table, $this->heap), false);

        self::assertCount(2, $rows);
        self::assertSame(['id' => 1, 'name' => 'alice'], $rows[0]->toArray());
        self::assertSame(['id' => 2, 'name' => 'bob'], $rows[1]->toArray());
    }

    public function testKeysAreTheRowsRecordIds(): void
    {
        $id = $this->heap->insert($this->table->serializeRow(new Row(['id' => 1, 'name' => 'alice'])));

        foreach (new SeqScan($this->table, $this->heap) as $key => $row) {
            self::assertInstanceOf(RecordId::class, $key);
            self::assertTrue($id->equals($key));
        }
    }

    public function testEmptyHeapYieldsNothing(): void
    {
        self::assertSame([], iterator_to_array(new SeqScan($this->table, $this->heap), false));
    }

    public function testDeletedRowsAreSkipped(): void
    {
        $this->heap->insert($this->table->serializeRow(new Row(['id' => 1, 'name' => 'alice'])));
        $gone = $this->heap->insert($this->table->serializeRow(new Row(['id' => 2, 'name' => 'bob'])));
        $this->heap->delete($gone);

        $rows = iterator_to_array(new SeqScan($this->table, $this->heap), false);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->get('id'));
    }
}

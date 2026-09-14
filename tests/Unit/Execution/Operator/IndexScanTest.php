<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Operator\IndexScan;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Storage\BTreeIndex;
use PhpMiniDatabase\Storage\HeapFile;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class IndexScanTest extends TestCase
{
    use TemporaryDirectory;

    private Table $table;
    private HeapFile $heap;
    private BTreeIndex $index;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->table = new Table('users', [new Column('id', new IntType()), new Column('name', new VarcharType(50))]);
        $this->heap = HeapFile::open($this->path('heap.dat'));
        $this->index = BTreeIndex::open($this->path('idx.dat'), new IntType());

        foreach ([[1, 'alice'], [2, 'bob'], [3, 'carol']] as [$id, $name]) {
            $recordId = $this->heap->insert($this->table->serializeRow(new Row(['id' => $id, 'name' => $name])));
            $this->index->insert($id, $recordId);
        }
    }

    protected function tearDown(): void
    {
        $this->heap->close();
        $this->index->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testEqualsFindsTheMatchingRow(): void
    {
        $scan = IndexScan::equals($this->table, $this->heap, $this->index, 2);

        $rows = iterator_to_array($scan, false);

        self::assertCount(1, $rows);
        self::assertSame('bob', $rows[0]->get('name'));
    }

    public function testEqualsFindsNothingForAMissingValue(): void
    {
        $scan = IndexScan::equals($this->table, $this->heap, $this->index, 999);

        self::assertSame([], iterator_to_array($scan, false));
    }

    public function testRangeYieldsRowsInKeyOrder(): void
    {
        $scan = IndexScan::range($this->table, $this->heap, $this->index, 1, true, 2, true);

        $names = array_map(static fn (Row $r) => $r->get('name'), iterator_to_array($scan, false));

        self::assertSame(['alice', 'bob'], $names);
    }

    public function testAnUnboundedRangeYieldsEveryRow(): void
    {
        $scan = IndexScan::range($this->table, $this->heap, $this->index, null, true, null, true);

        self::assertCount(3, iterator_to_array($scan, false));
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Storage;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Storage\BTreeIndex;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BTreeIndexTest extends TestCase
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

    /** @return list<RecordId> */
    private function searchAll(BTreeIndex $index, mixed $value): array
    {
        return iterator_to_array($index->search($value), false);
    }

    /** @return list<RecordId> */
    private function rangeAll(BTreeIndex $index, mixed $low, bool $lowInclusive, mixed $high, bool $highInclusive): array
    {
        return iterator_to_array($index->range($low, $lowInclusive, $high, $highInclusive), false);
    }

    public function testASearchOnAnEmptyIndexFindsNothing(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());

        self::assertSame([], $this->searchAll($index, 42));
    }

    public function testInsertThenSearchFindsTheRecord(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        $index->insert(42, new RecordId(1, 0));

        $found = $this->searchAll($index, 42);

        self::assertCount(1, $found);
        self::assertTrue($found[0]->equals(new RecordId(1, 0)));
    }

    public function testSearchingAMissingKeyFindsNothing(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        $index->insert(1, new RecordId(0, 0));

        self::assertSame([], $this->searchAll($index, 999));
    }

    public function testANonUniqueIndexAllowsSeveralRecordsPerKey(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        $index->insert(1, new RecordId(0, 0));
        $index->insert(1, new RecordId(0, 1));

        self::assertCount(2, $this->searchAll($index, 1));
    }

    public function testAUniqueIndexRejectsADuplicateKey(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType(), unique: true);
        $index->insert(1, new RecordId(0, 0));

        $this->expectException(ConstraintViolationException::class);
        $index->insert(1, new RecordId(0, 1));
    }

    public function testNullValuesAreNeverIndexed(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType(), unique: true);
        $index->insert(null, new RecordId(0, 0));
        $index->insert(null, new RecordId(0, 1)); // two NULLs, still not a "duplicate"

        self::assertSame([], $this->searchAll($index, null));
    }

    public function testDeleteRemovesExactlyThatRecord(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        $index->insert(1, new RecordId(0, 0));
        $index->insert(1, new RecordId(0, 1));

        $index->delete(1, new RecordId(0, 0));

        $found = $this->searchAll($index, 1);
        self::assertCount(1, $found);
        self::assertTrue($found[0]->equals(new RecordId(0, 1)));
    }

    public function testDeletingAMissingEntryThrows(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());

        $this->expectException(StorageException::class);
        $index->delete(1, new RecordId(0, 0));
    }

    public function testDeletingANullValueIsANoOp(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());

        $index->delete(null, new RecordId(0, 0));

        self::assertSame([], $this->searchAll($index, null));
    }

    public function testStringKeysCompareLexicallyNotByLength(): void
    {
        // Byte-order pitfall this index has to avoid: "AA" must sort after
        // "B" (lexical) even though it is longer.
        $index = BTreeIndex::open($this->path('idx.dat'), new VarcharType(10));

        foreach (['B', 'AA', 'A'] as $i => $value) {
            $index->insert($value, new RecordId(0, $i));
        }

        $ordered = array_map(
            static fn (RecordId $id): int => $id->slot,
            $this->rangeAll($index, null, true, null, true),
        );

        // Slot 2 is "A", slot 1 is "AA", slot 0 is "B" - lexical order.
        self::assertSame([2, 1, 0], $ordered);
    }

    public function testRangeIsInclusiveByDefault(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        foreach ([1, 2, 3, 4, 5] as $i => $value) {
            $index->insert($value, new RecordId(0, $i));
        }

        $values = array_map(static fn (RecordId $id): int => $id->slot, $this->rangeAll($index, 2, true, 4, true));

        self::assertSame([1, 2, 3], $values);
    }

    public function testRangeCanExcludeEitherBound(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        foreach ([1, 2, 3, 4, 5] as $i => $value) {
            $index->insert($value, new RecordId(0, $i));
        }

        $values = array_map(static fn (RecordId $id): int => $id->slot, $this->rangeAll($index, 2, false, 4, false));

        self::assertSame([2], $values);
    }

    public function testRangeWithNoLowerBound(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        foreach ([3, 1, 2] as $i => $value) {
            $index->insert($value, new RecordId(0, $i));
        }

        $values = array_map(static fn (RecordId $id): int => $id->slot, $this->rangeAll($index, null, true, 2, true));

        self::assertSame([1, 2], $values); // key 1 (slot 1), key 2 (slot 2)
    }

    public function testRangeWithNoUpperBound(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        foreach ([3, 1, 2] as $i => $value) {
            $index->insert($value, new RecordId(0, $i));
        }

        $values = array_map(static fn (RecordId $id): int => $id->slot, $this->rangeAll($index, 2, true, null, true));

        self::assertSame([2, 0], $values); // key 2 (slot 2), key 3 (slot 0)
    }

    public function testUnboundedRangeVisitsEveryEntryInOrder(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        foreach ([5, 3, 1, 4, 2] as $i => $value) {
            $index->insert($value, new RecordId(0, $i));
        }

        $values = array_map(static fn (RecordId $id): int => $id->slot, $this->rangeAll($index, null, true, null, true));

        self::assertSame([2, 4, 1, 3, 0], $values); // keys 1,2,3,4,5 in that key order
    }

    /**
     * Enough inserts to force at least one leaf split and, eventually, a
     * root split into a second level - proof the tree structure itself,
     * not just single-page behaviour, is correct.
     */
    public function testManyInsertsForceSplitsAndEverythingStaysFindable(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        $count = 2000;

        for ($i = 0; $i < $count; $i++) {
            $index->insert($i, new RecordId(0, $i));
        }

        // Every key, not a sample of six. A leaf split promotes the right
        // half's first *whole* key - value bytes plus RecordId - as the
        // separator, while a search descends by the value's lowest
        // possible whole key (an all-zero RecordId suffix), so the descent
        // lands one leaf left of the entry: one key per split used to
        // disappear, and sampling walked straight past all of them.
        $missing = [];

        for ($key = 0; $key < $count; $key++) {
            $found = $this->searchAll($index, $key);

            if ($found === [] || $found[0]->slot !== $key) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing);

        self::assertSame([], $this->searchAll($index, $count));

        $all = $this->rangeAll($index, null, true, null, true);
        self::assertCount($count, $all);
        self::assertSame(range(0, $count - 1), array_map(static fn (RecordId $id): int => $id->slot, $all));
    }

    public function testDeletingAcrossManyEntriesLeavesTheRestFindable(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());
        $count = 500;

        for ($i = 0; $i < $count; $i++) {
            $index->insert($i, new RecordId(0, $i));
        }

        for ($i = 0; $i < $count; $i += 2) {
            $index->delete($i, new RecordId(0, $i));
        }

        foreach (range(0, $count - 1) as $i) {
            $found = $this->searchAll($index, $i);
            self::assertCount($i % 2 === 0 ? 0 : 1, $found, "key {$i}");
        }
    }

    public function testTheIndexSurvivesReopening(): void
    {
        $path = $this->path('idx.dat');
        $index = BTreeIndex::open($path, new IntType());
        $index->insert(1, new RecordId(0, 0));
        $index->insert(2, new RecordId(0, 1));
        $index->close();

        $reopened = BTreeIndex::open($path, new IntType());

        self::assertCount(1, $this->searchAll($reopened, 1));
        self::assertCount(1, $this->searchAll($reopened, 2));
    }

    /**
     * A duplicate key that spills onto the next leaf after a split must
     * still be found - and deletable - by chaining across the leaf link,
     * not just by looking at the one leaf the key first descends to.
     */
    public function testADuplicateKeySpanningALeafSplitIsStillFullyFindableAndDeletable(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new IntType());

        // Enough distinct keys to force splits, all sharing one repeated
        // key interspersed throughout so some copies end up on different
        // leaves after splitting.
        for ($i = 0; $i < 1000; $i++) {
            $index->insert(1, new RecordId(0, $i));
        }

        self::assertCount(1000, $this->searchAll($index, 1));

        $index->delete(1, new RecordId(0, 500));

        self::assertCount(999, $this->searchAll($index, 1));
    }

    /**
     * The same scan backs a unique index's duplicate check, so a key it
     * could not find was a key that could be inserted twice - a PRIMARY
     * KEY admitting two rows with the same id, which is the same bug
     * wearing its worse face.
     */
    public function testNoKeyCanBeDuplicatedOnceTheLeavesHaveSplit(): void
    {
        $index = BTreeIndex::open($this->path('idx.dat'), new VarcharType(40), unique: true);
        $keys = [];

        for ($i = 0; $i < 600; $i++) {
            $key = sprintf('order-%05d', $i);
            $keys[] = $key;
            $index->insert($key, new RecordId(intdiv($i, 20) + 1, $i % 20));
        }

        $accepted = [];

        foreach ($keys as $key) {
            try {
                $index->insert($key, new RecordId(9999, 0));
                $accepted[] = $key;
            } catch (ConstraintViolationException) {
                // what every one of them must do
            }
        }

        self::assertSame([], $accepted);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Storage;

use PhpMiniDatabase\Storage\HeapFile;
use PhpMiniDatabase\Storage\Page;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HeapFileTest extends TestCase
{
    use TemporaryDirectory;

    private HeapFile $heap;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->heap = HeapFile::open($this->path('heap.dat'));
    }

    protected function tearDown(): void
    {
        $this->heap->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testARecordReadsBackByItsId(): void
    {
        $id = $this->heap->insert('a row');

        self::assertSame('a row', $this->heap->read($id));
    }

    public function testRecordsGetDistinctIds(): void
    {
        $first = $this->heap->insert('one');
        $second = $this->heap->insert('two');

        self::assertFalse($first->equals($second));
        self::assertSame('one', $this->heap->read($first));
        self::assertSame('two', $this->heap->read($second));
    }

    public function testADeletedRecordReadsAsNull(): void
    {
        $id = $this->heap->insert('gone soon');

        $this->heap->delete($id);

        self::assertNull($this->heap->read($id));
    }

    public function testScanYieldsEveryLiveRecordWithItsId(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $record) {
            $ids[$record] = (string) $this->heap->insert($record);
        }

        $this->heap->delete(RecordId::fromString($ids['b']));

        $seen = [];
        foreach ($this->heap->scan() as $id => $record) {
            $seen[$record] = (string) $id;
        }

        self::assertSame(['a' => $ids['a'], 'c' => $ids['c']], $seen);
    }

    public function testScanIsEmptyForANewFile(): void
    {
        self::assertSame([], iterator_to_array($this->heap->scan(), false));
    }

    /**
     * Records spill onto further pages once one is full, and the file keeps
     * reading as one flat sequence regardless.
     */
    public function testRecordsSpillOntoFurtherPages(): void
    {
        $count = 60;
        $record = str_repeat('x', 512);

        for ($i = 0; $i < $count; $i++) {
            $this->heap->insert($record . $i);
        }

        self::assertGreaterThan(Page::SIZE, filesize($this->path('heap.dat')));
        self::assertCount($count, iterator_to_array($this->heap->scan(), false));
    }

    public function testUpdateInPlaceKeepsTheRecordId(): void
    {
        $id = $this->heap->insert('small');

        $after = $this->heap->update($id, 'a little bigger');

        self::assertTrue($id->equals($after));
        self::assertSame('a little bigger', $this->heap->read($after));
    }

    /**
     * A record that outgrows its page has to move, and update() reports the
     * move by returning the new id. Indexes follow that return value rather
     * than the id they passed in — this is the case that makes them.
     */
    public function testARecordThatOutgrowsItsPageMovesAndReportsItsNewId(): void
    {
        $id = $this->heap->insert('small');
        $this->heap->insert(str_repeat('filler', 1300));

        $after = $this->heap->update($id, str_repeat('grown', 1000));

        self::assertFalse($id->equals($after));
        self::assertSame(str_repeat('grown', 1000), $this->heap->read($after));
        self::assertNull($this->heap->read($id));
    }

    public function testContentsSurviveReopening(): void
    {
        $id = $this->heap->insert('durable');
        $this->heap->sync();
        $this->heap->close();

        $this->heap = HeapFile::open($this->path('heap.dat'));

        self::assertSame('durable', $this->heap->read($id));
    }

    public function testInsertsAfterReopeningDoNotOverwriteExistingRecords(): void
    {
        $first = $this->heap->insert('before');
        $this->heap->close();

        $this->heap = HeapFile::open($this->path('heap.dat'));
        $second = $this->heap->insert('after');

        self::assertSame('before', $this->heap->read($first));
        self::assertSame('after', $this->heap->read($second));
    }

    public function testVacuumDropsDeletedRecordsAndShrinksTheFile(): void
    {
        $keep = [];
        for ($i = 0; $i < 60; $i++) {
            $id = $this->heap->insert(str_repeat('x', 512) . $i);

            if ($i % 2 === 0) {
                $keep[] = str_repeat('x', 512) . $i;
                continue;
            }

            $this->heap->delete($id);
        }

        $sizeBefore = filesize($this->path('heap.dat'));
        $reclaimed = $this->heap->vacuum();

        self::assertGreaterThan(0, $reclaimed);
        self::assertSame($sizeBefore - $reclaimed, filesize($this->path('heap.dat')));
        self::assertSame($keep, iterator_to_array($this->heap->scan(), false));
    }

    public function testVacuumLeavesTheFileWritable(): void
    {
        $this->heap->insert('kept');
        $this->heap->vacuum();

        $id = $this->heap->insert('added after vacuum');

        self::assertSame('added after vacuum', $this->heap->read($id));
        self::assertCount(2, iterator_to_array($this->heap->scan(), false));
    }

    public function testVacuumLeavesNoTemporaryFileBehind(): void
    {
        $this->heap->insert('kept');
        $this->heap->vacuum();

        self::assertFileDoesNotExist($this->path('heap.dat.vacuum'));
    }

    public function testRewritePutsEveryRecordThroughTheTransform(): void
    {
        $this->heap->insert('one');
        $this->heap->insert('two');

        $this->heap->rewrite(static fn (string $record): string => strtoupper($record));

        self::assertSame(['ONE', 'TWO'], iterator_to_array($this->heap->scan(), false));
    }

    public function testRewriteDropsTheSpaceDeletedRecordsHeld(): void
    {
        $this->heap->delete($this->heap->insert('gone'));
        $this->heap->insert('kept');

        $this->heap->rewrite(static fn (string $record): string => $record);

        self::assertSame(['kept'], iterator_to_array($this->heap->scan(), false));
    }

    public function testRewriteLeavesTheFileWritable(): void
    {
        $this->heap->insert('kept');
        $this->heap->rewrite(static fn (string $record): string => $record);

        $id = $this->heap->insert('added after rewrite');

        self::assertSame('added after rewrite', $this->heap->read($id));
        self::assertCount(2, iterator_to_array($this->heap->scan(), false));
    }

    public function testAFailedRewriteLeavesTheOriginalFileUntouched(): void
    {
        $this->heap->insert('one');
        $this->heap->insert('two');

        try {
            $this->heap->rewrite(static function (string $record): string {
                if ($record === 'two') {
                    throw new RuntimeException('no');
                }

                return strtoupper($record);
            });
            self::fail('Expected the transform\'s exception to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame(['one', 'two'], iterator_to_array($this->heap->scan(), false));
        self::assertFileDoesNotExist($this->path('heap.dat.rewrite'));
    }
}

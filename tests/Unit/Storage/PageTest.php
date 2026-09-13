<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Storage;

use MiniDatabase\Exception\StorageException;
use MiniDatabase\Storage\Page;
use MiniDatabase\Storage\PageType;
use PHPUnit\Framework\TestCase;

/**
 * The page is where the on-disk format is decided, so these tests are as
 * much about the bytes as about the behaviour: a page that round-trips but
 * writes a different layout than documented would pass a purely behavioural
 * suite and break every reader written against the format.
 */
final class PageTest extends TestCase
{
    public function testAnEmptyPageIsExactlyOnePageLong(): void
    {
        $bytes = Page::create(0, PageType::HEAP)->toBytes();

        self::assertSame(Page::SIZE, strlen($bytes));
    }

    public function testHeaderCarriesIdTypeAndSlotCount(): void
    {
        $page = Page::create(7, PageType::HEAP);
        $page->insert('ab');
        $page->insert('cde');

        /** @var array{id: int, type: int, slotCount: int, freeEnd: int} $header */
        $header = unpack('Nid/ntype/nslotCount/nfreeEnd', $page->toBytes());

        self::assertSame(7, $header['id']);
        self::assertSame(PageType::HEAP->value, $header['type']);
        self::assertSame(2, $header['slotCount']);
        // Five bytes of records, laid out against the end of the page.
        self::assertSame(Page::SIZE - 5, $header['freeEnd']);
    }

    public function testRecordsRoundTripThroughBytes(): void
    {
        $page = Page::create(3, PageType::HEAP);
        $first = $page->insert('first record');
        $second = $page->insert("second\x00with\xffbinary");

        $restored = Page::fromBytes(3, $page->toBytes());

        self::assertSame('first record', $restored->read((int) $first));
        self::assertSame("second\x00with\xffbinary", $restored->read((int) $second));
        self::assertSame(3, $restored->id);
        self::assertSame(PageType::HEAP, $restored->type);
    }

    public function testSlotNumbersStartAtZeroAndCountUp(): void
    {
        $page = Page::create(0, PageType::HEAP);

        self::assertSame(0, $page->insert('a'));
        self::assertSame(1, $page->insert('b'));
        self::assertSame(2, $page->insert('c'));
    }

    /**
     * The point of a slot directory: a record's address survives its
     * neighbours moving. Deleting the first record shifts the bytes of the
     * others, and their slot numbers must not notice.
     */
    public function testDeletingANeighbourLeavesOtherSlotsAddressable(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $page->insert('first');
        $second = (int) $page->insert('second');
        $third = (int) $page->insert('third');

        $page->delete(0);

        $restored = Page::fromBytes(0, $page->toBytes());

        self::assertNull($restored->read(0));
        self::assertSame('second', $restored->read($second));
        self::assertSame('third', $restored->read($third));
    }

    public function testDeletedSlotIsReusedBeforeTheDirectoryGrows(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $page->insert('a');
        $page->insert('b');

        $page->delete(0);

        self::assertSame(0, $page->insert('c'));
        self::assertSame(2, $page->slotCount());
    }

    public function testDeletingTwiceIsAnError(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $page->insert('a');
        $page->delete(0);

        $this->expectException(StorageException::class);
        $page->delete(0);
    }

    public function testUpdateInPlaceKeepsTheSlot(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $slot = (int) $page->insert('short');

        self::assertTrue($page->update($slot, 'a much longer record'));
        self::assertSame('a much longer record', Page::fromBytes(0, $page->toBytes())->read($slot));
    }

    public function testUpdateRefusesWhatDoesNotFit(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $slot = (int) $page->insert(str_repeat('x', 4000));
        $page->insert(str_repeat('y', 4000));

        self::assertFalse($page->update($slot, str_repeat('z', 5000)));
        self::assertSame(str_repeat('x', 4000), $page->read($slot));
    }

    /**
     * "Full" is relative to the record being stored: a page that has no room
     * for another 512 bytes may still have room for one. Only a record that
     * cannot fit the remaining space is refused.
     */
    public function testInsertReturnsNullOnceTheRecordNoLongerFits(): void
    {
        $page = Page::create(0, PageType::HEAP);

        while ($page->insert(str_repeat('x', 512)) !== null) {
            // fill it
        }

        self::assertNull($page->insert(str_repeat('x', 512)));
        self::assertGreaterThan(0, $page->recordCount());
        self::assertLessThan(512, $page->freeSpace());
    }

    public function testFreeSpaceAccountsForTheDirectoryEntry(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $before = $page->freeSpace();

        $page->insert('12345');

        // Five bytes of record plus the four byte slot it needed.
        self::assertSame($before - 9, $page->freeSpace());
    }

    public function testARecordLargerThanAPageIsRefused(): void
    {
        $this->expectException(StorageException::class);
        Page::create(0, PageType::HEAP)->insert(str_repeat('x', Page::MAX_RECORD_SIZE + 1));
    }

    public function testARecordOfExactlyTheMaximumFits(): void
    {
        $page = Page::create(0, PageType::HEAP);

        self::assertSame(0, $page->insert(str_repeat('x', Page::MAX_RECORD_SIZE)));
    }

    public function testOccupiedSlotsSkipsTombstones(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $page->insert('a');
        $page->insert('b');
        $page->insert('c');
        $page->delete(1);

        self::assertSame([0, 2], $page->occupiedSlots());
        self::assertSame(2, $page->recordCount());
        self::assertSame(3, $page->slotCount());
    }

    public function testBytesOfTheWrongLengthAreRejected(): void
    {
        $this->expectException(StorageException::class);
        Page::fromBytes(0, str_repeat("\x00", 100));
    }

    /**
     * The id in the header is a check against reading the wrong page, which
     * is what a mis-seek or a truncated file looks like from here.
     */
    public function testAPageReadAtTheWrongNumberIsRejected(): void
    {
        $bytes = Page::create(4, PageType::HEAP)->toBytes();

        $this->expectException(StorageException::class);
        Page::fromBytes(5, $bytes);
    }

    public function testAnUnknownPageTypeIsRejected(): void
    {
        $bytes = Page::create(0, PageType::HEAP)->toBytes();
        $corrupted = substr_replace($bytes, pack('n', 99), 4, 2);

        $this->expectException(StorageException::class);
        Page::fromBytes(0, $corrupted);
    }

    public function testASlotPointingOutsideTheRecordAreaIsRejected(): void
    {
        $page = Page::create(0, PageType::HEAP);
        $page->insert('record');

        // Point slot 0 one byte past the end of the page.
        $corrupted = substr_replace($page->toBytes(), pack('nn', Page::SIZE, 6), 10, 4);

        $this->expectException(StorageException::class);
        Page::fromBytes(0, $corrupted);
    }
}

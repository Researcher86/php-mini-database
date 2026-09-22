<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use PhpMiniDatabase\Exception\StorageException;

/**
 * A fixed-size slotted page: the unit the database reads from and writes to
 * disk, and the only place a record's bytes actually live.
 *
 * The on-disk layout is the classic one:
 *
 *   0 +--------------------------------------+
 *     | pageId    uint32                     |
 *   4 | type      uint16                     |
 *   6 | slotCount uint16                     |
 *   8 | freeEnd   uint16                     |  where the record area starts
 *  10 +--------------------------------------+
 *     | slot 0: offset uint16, length uint16 |  directory, grows down
 *     | slot 1: ...                          |
 *     +--------------------------------------+
 *     | free space                           |
 *     +--------------------------------------+
 *     | record data, grows up from the end   |
 *  8192 +------------------------------------+
 *
 * The directory grows from the front and the data from the back, so the two
 * meet in the middle and a page is full exactly when they touch. A record is
 * addressed by its *slot number*, not by its offset, which is what lets the
 * bytes move within the page — during a rewrite, or when a neighbour is
 * deleted — without invalidating anything that points at it. An index entry
 * or a RecordId names (page, slot) and stays correct.
 *
 * A deleted slot becomes a tombstone: offset 0, length 0. Offset 0 is inside
 * the header and can never be a real record, so no separate flag is needed.
 * The four directory bytes stay, which keeps every later slot number stable;
 * insert() reuses a tombstone before appending, so a delete/insert cycle does
 * not grow the directory forever.
 *
 * In memory the page is held *decoded* — a list of record strings, one per
 * slot, with null for a tombstone — and laid out only in toBytes(). A page
 * is never written partially (PageManager writes all 8 KiB or none), so
 * there is nothing to gain from mutating a byte buffer in place, and a great
 * deal of arithmetic to get wrong.
 */
final class Page
{
    public const SIZE = 8192;

    private const HEADER_SIZE = 10;
    private const SLOT_SIZE = 4;

    /**
     * The largest record that can ever be stored: an otherwise empty page,
     * minus the header and the one directory entry the record itself needs.
     * Rows above this need overflow pages, which this database does not have
     * — it refuses them instead of silently truncating.
     */
    public const MAX_RECORD_SIZE = self::SIZE - self::HEADER_SIZE - self::SLOT_SIZE;

    private function __construct(
        public readonly int $id,
        public readonly PageType $type,
        /**
         * Record bytes by slot number; null marks a tombstone. Not typed
         * `list<string|null>`: `insert()` writes into an existing
         * tombstone slot by its own (already in-bounds) index, which
         * PHPStan cannot itself verify keeps the array contiguous even
         * though it always does here.
         *
         * @var array<int, string|null>
         */
        private array $records = [],
    ) {
    }

    public static function create(int $id, PageType $type): self
    {
        return new self($id, $type);
    }

    /**
     * @throws StorageException when the bytes are not a page of this format,
     *                          or are a page with a different id than the
     *                          one the caller expected to read
     */
    public static function fromBytes(int $expectedId, string $bytes): self
    {
        if (strlen($bytes) !== self::SIZE) {
            throw new StorageException(sprintf('Page %d is %d bytes, expected %d.', $expectedId, strlen($bytes), self::SIZE));
        }

        /** @var array{id: int, type: int, slotCount: int, freeEnd: int} $header */
        $header = unpack('Nid/ntype/nslotCount/nfreeEnd', $bytes);

        if ($header['id'] !== $expectedId) {
            throw new StorageException(sprintf('Page at slot %d reports id %d.', $expectedId, $header['id']));
        }

        $type = PageType::tryFrom($header['type'])
            ?? throw new StorageException(sprintf('Page %d has unknown type %d.', $expectedId, $header['type']));

        $records = [];

        for ($slot = 0; $slot < $header['slotCount']; $slot++) {
            /** @var array{offset: int, length: int} $entry */
            $entry = unpack('noffset/nlength', substr($bytes, self::HEADER_SIZE + $slot * self::SLOT_SIZE, self::SLOT_SIZE));

            if ($entry['offset'] === 0) {
                $records[] = null;
                continue;
            }

            if ($entry['offset'] < $header['freeEnd'] || $entry['offset'] + $entry['length'] > self::SIZE) {
                throw new StorageException(sprintf('Page %d slot %d points outside the record area.', $expectedId, $slot));
            }

            $records[] = substr($bytes, $entry['offset'], $entry['length']);
        }

        return new self($expectedId, $type, $records);
    }

    public function toBytes(): string
    {
        $directory = '';
        $data = '';
        $offset = self::SIZE;

        // Records are laid out back to front, so each one's offset is known
        // only after the ones already placed. The directory is built in slot
        // order alongside, which keeps slot numbers independent of where the
        // bytes ended up.
        foreach ($this->records as $record) {
            if ($record === null) {
                $directory .= pack('nn', 0, 0);
                continue;
            }

            $offset -= strlen($record);
            $data = $record . $data;
            $directory .= pack('nn', $offset, strlen($record));
        }

        $header = pack('Nnnn', $this->id, $this->type->value, count($this->records), $offset);
        $gap = self::SIZE - strlen($header) - strlen($directory) - strlen($data);

        return $header . $directory . str_repeat("\x00", $gap) . $data;
    }

    /**
     * Store a record and return its slot number, or null when the page has
     * no room. Null is a normal answer, not an error: it is how HeapFile
     * learns to look at another page.
     */
    public function insert(string $record): ?int
    {
        if (strlen($record) > self::MAX_RECORD_SIZE) {
            throw new StorageException(sprintf(
                'Record of %d bytes exceeds the %d byte maximum.',
                strlen($record),
                self::MAX_RECORD_SIZE,
            ));
        }

        $tombstone = $this->firstTombstone();
        $needed = strlen($record) + ($tombstone === null ? self::SLOT_SIZE : 0);

        if ($needed > $this->freeSpace()) {
            return null;
        }

        if ($tombstone !== null) {
            $this->records[$tombstone] = $record;

            return $tombstone;
        }

        $this->records[] = $record;

        return count($this->records) - 1;
    }

    /** The record in a slot, or null if the slot is a tombstone. */
    public function read(int $slot): ?string
    {
        return $this->records[$slot] ?? null;
    }

    /**
     * Replace a record in place. Returns false when the new record does not
     * fit alongside what else is on the page — the caller then deletes and
     * inserts elsewhere, which changes the record's id.
     */
    public function update(int $slot, string $record): bool
    {
        $existing = $this->records[$slot] ?? null;

        if ($existing === null) {
            throw new StorageException(sprintf('Page %d slot %d holds no record.', $this->id, $slot));
        }

        if (strlen($record) - strlen($existing) > $this->freeSpace()) {
            return false;
        }

        $this->records[$slot] = $record;

        return true;
    }

    public function delete(int $slot): void
    {
        if (($this->records[$slot] ?? null) === null) {
            throw new StorageException(sprintf('Page %d slot %d holds no record.', $this->id, $slot));
        }

        $this->records[$slot] = null;
    }

    /** Slot numbers ever handed out, tombstones included. */
    public function slotCount(): int
    {
        return count($this->records);
    }

    /** Slots that currently hold a record. */
    public function recordCount(): int
    {
        return count($this->occupiedSlots());
    }

    /**
     * Slot numbers that hold a record, in order.
     *
     * @return list<int>
     */
    public function occupiedSlots(): array
    {
        return array_keys(array_filter($this->records, static fn (?string $record): bool => $record !== null));
    }

    /**
     * Bytes available for record data, with the directory entries already
     * charged for. A caller appending a new slot needs SLOT_SIZE of this on
     * top of its record.
     */
    public function freeSpace(): int
    {
        $used = self::HEADER_SIZE + count($this->records) * self::SLOT_SIZE;

        foreach ($this->records as $record) {
            $used += $record === null ? 0 : strlen($record);
        }

        return self::SIZE - $used;
    }

    private function firstTombstone(): ?int
    {
        foreach ($this->records as $slot => $record) {
            if ($record === null) {
                return $slot;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use Generator;
use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Schema\Type\BlobType;
use PhpMiniDatabase\Schema\Type\Type;
use PhpMiniDatabase\Schema\Type\VarcharType;

/**
 * A B+Tree over a single column: every key in an internal node's own page
 * or a leaf, entries kept in sorted order, leaves linked left to right for
 * an ordered range scan without re-descending the tree per key.
 *
 * ### The key actually stored is not the indexed value
 *
 * A leaf's key is the indexed value's sortable bytes *followed by the
 * row's 8-byte `RecordId`*, never the value alone. Two rows with the same
 * indexed value — routine for a non-unique index — would otherwise be
 * *the same key twice*, and a B+Tree's separators cannot tell two equal
 * keys apart: an internal node splits by promoting one of them as a
 * separator, and a search descending past a separator equal to the key it
 * is looking for has no way to know that entries with that same value
 * still exist in the child on the *other* side of it. Appending the
 * `RecordId` makes every stored key unique even when the value repeats, so
 * separators are always unambiguous. A lookup for a value searches for the
 * range of stored keys carrying that value as their prefix — `search()`
 * and `range()` build the right prefix bounds for this once, and
 * everywhere else in this class just compares whole keys with `strcmp()`
 * as if they were ordinary, unique ones.
 *
 * ### Where entries live
 *
 * Every node — internal or leaf — is one `Storage\Page`, exactly as a
 * `HeapFile` page holds table rows: this class supplies no page format of
 * its own, only a way to pack one entry into the bytes a page slot already
 * frames. A leaf entry is `[0x01][key bytes]` (the trailing 8 bytes of the
 * key *are* the `RecordId`, so nothing further needs to be stored
 * alongside it); an internal entry is `[0x01][key bytes][4-byte child page
 * id]`; and each node's first slot is reserved for a `0x00`-tagged one
 * holding whatever that node type needs instead of a key — the next
 * leaf's page id for a leaf, or the "less than every real key here" child
 * pointer for an internal node.
 *
 * Page 0 of the file is always a `BTREE_META` page holding the current
 * root's page id — the one piece of the tree whose identity changes as it
 * grows a level.
 *
 * ### What is simplified, on purpose
 *
 * A node already holding room for a new entry is edited via `Page::insert()`
 * directly and rewritten as-is — cheap, since a page never needs to be kept
 * in sorted order internally (every read decodes and re-sorts). Only a
 * node that is actually full pays for decoding its whole entry list,
 * because that is also the only time it needs one, to split it in half.
 *
 * A delete removes its entry and nothing else: there is no rebalancing or
 * merging of an underflowed node, the same trade `HeapFile` already made
 * (space comes back at a rebuild, not on every delete). `DROP INDEX` +
 * `CREATE INDEX` is today's way to reclaim a heavily deleted index.
 *
 * A `NULL` value is never inserted, searched for, or found: three-valued
 * logic already says `x = NULL` is never true, so a key that cannot
 * possibly be found by equality has nothing to gain from being indexed.
 *
 * Only a single column is supported — a composite key would need every
 * component but the last to be fixed-width or independently length-framed,
 * or two different multi-column values could concatenate to the same
 * bytes; deferred rather than approximated.
 */
final class BTreeIndex
{
    private const TAG_ENTRY = "\x01";
    private const TAG_SPECIAL = "\x00";
    private const NO_PAGE = 0xFFFFFFFF;
    private const RECORD_ID_LENGTH = 8;

    public function __construct(
        private readonly PageManager $pages,
        private readonly Type $keyType,
        public readonly bool $unique = false,
    ) {
        if ($pages->pageCount() === 0) {
            $this->initialize();
        }
    }

    public static function open(string $path, Type $keyType, bool $unique = false): self
    {
        return new self(new PageManager($path), $keyType, $unique);
    }

    /**
     * @throws ConstraintViolationException when this is a unique index and
     *                                      the value is already present
     */
    public function insert(mixed $value, RecordId $id): void
    {
        $prefix = $this->valuePrefix($value);

        if ($prefix === null) {
            return;
        }

        if ($this->unique && $this->prefixExists($prefix)) {
            throw new ConstraintViolationException(sprintf('Duplicate key "%s" in a unique index.', $this->describe($value)));
        }

        $split = $this->insertIntoNode($this->rootPageId(), $prefix . $this->encodeRecordId($id));

        if ($split !== null) {
            $this->growNewRoot($split);
        }
    }

    /** @throws StorageException when the exact (value, id) pair is not present */
    public function delete(mixed $value, RecordId $id): void
    {
        $prefix = $this->valuePrefix($value);

        if ($prefix === null) {
            return;
        }

        // The full key (value + this exact RecordId) is unique, so it
        // names one leaf directly - no need to chain across leaves the way
        // a value-only search does.
        $fullKey = $prefix . $this->encodeRecordId($id);
        $pageId = $this->leafPageIdFor($fullKey);
        $entries = $this->readLeafEntries($this->pages->read($pageId));

        $index = array_search($fullKey, $entries, true);

        if ($index === false) {
            throw new StorageException(sprintf('Key "%s" -> %s is not present in the index.', $this->describe($value), $id));
        }

        unset($entries[$index]);
        $this->writeLeaf($pageId, array_values($entries), $this->nextLeafPageId($this->pages->read($pageId)));
    }

    /** @return Generator<RecordId> */
    public function search(mixed $value): Generator
    {
        $prefix = $this->valuePrefix($value);

        if ($prefix === null) {
            return;
        }

        yield from $this->scanPrefix($prefix);
    }

    /**
     * Every RecordId whose key falls within `[$low, $high]`, in ascending
     * key order. Either bound is `null` for "unbounded on this side".
     *
     * @return Generator<RecordId>
     */
    public function range(mixed $low, bool $lowInclusive, mixed $high, bool $highInclusive): Generator
    {
        $lowBound = $low === null ? null : $this->boundary($this->valuePrefix($low), $lowInclusive);
        $highBound = $high === null ? null : $this->boundary($this->valuePrefix($high), !$highInclusive);

        $pageId = $lowBound === null ? $this->leftmostLeafPageId() : $this->leafPageIdFor($lowBound);

        while ($pageId !== null) {
            foreach ($this->readLeafEntries($this->pages->read($pageId)) as $entry) {
                if ($lowBound !== null && strcmp($entry, $lowBound) < 0) {
                    continue;
                }

                if ($highBound !== null && strcmp($entry, $highBound) > 0) {
                    return;
                }

                yield $this->recordIdFromKey($entry);
            }

            $pageId = $this->nextLeafPageId($this->pages->read($pageId));
        }
    }

    public function close(): void
    {
        $this->pages->close();
    }

    // -----------------------------------------------------------------
    // Value <-> key
    // -----------------------------------------------------------------

    /**
     * The sortable bytes for one value, *without* the RecordId suffix —
     * fixed-width ordered types (INT, BIGINT, DECIMAL, BOOL, DATE,
     * DATETIME) already encode this way (Phase 1's sign-flipped,
     * byte-order-is-value-order format); VARCHAR and BLOB use their raw
     * canonical bytes instead of `Type::encode()`'s length-prefixed form,
     * because a length prefix would compare shorter strings as "greater"
     * than longer ones that are lexically smaller. Returns null for a NULL
     * value, meaning "do not index this".
     */
    private function valuePrefix(mixed $value): ?string
    {
        $canonical = $this->keyType->cast($value);

        if ($canonical === null) {
            return null;
        }

        if ($this->keyType instanceof VarcharType || $this->keyType instanceof BlobType) {
            return (string) $canonical;
        }

        return $this->keyType->encode($canonical);
    }

    /**
     * A stored key naming every entry for $prefix, whichever RecordId they
     * carry: the lowest possible suffix (all zero bytes) when
     * $inclusiveOfPrefix is true, so comparing >= it matches the prefix
     * itself; the highest possible suffix (all one bytes) otherwise, so
     * comparing > it skips the prefix entirely. Used to turn a value-level
     * range bound into a whole-key comparison.
     */
    private function boundary(?string $prefix, bool $inclusiveOfPrefix): ?string
    {
        if ($prefix === null) {
            return null;
        }

        return $prefix . str_repeat($inclusiveOfPrefix ? "\x00" : "\xff", self::RECORD_ID_LENGTH);
    }

    private function encodeRecordId(RecordId $id): string
    {
        return pack('NN', $id->pageId, $id->slot);
    }

    private function recordIdFromKey(string $fullKey): RecordId
    {
        /** @var array{page: int, slot: int} $parts */
        $parts = unpack('Npage/Nslot', substr($fullKey, -self::RECORD_ID_LENGTH));

        return new RecordId($parts['page'], $parts['slot']);
    }

    /** A value safe to interpolate into an error message, whatever its type. */
    private function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    // -----------------------------------------------------------------
    // Prefix scans (search, uniqueness check)
    // -----------------------------------------------------------------

    /** @return Generator<RecordId> */
    private function scanPrefix(string $prefix): Generator
    {
        $pageId = $this->leafPageIdFor($this->boundary($prefix, true));

        while ($pageId !== null) {
            $sawMatch = false;

            foreach ($this->readLeafEntries($this->pages->read($pageId)) as $entry) {
                if (substr($entry, 0, -self::RECORD_ID_LENGTH) === $prefix) {
                    $sawMatch = true;
                    yield $this->recordIdFromKey($entry);
                } elseif ($sawMatch) {
                    return; // past the (contiguous) run of matching entries
                }
            }

            // The run can end exactly at a leaf boundary; keep following
            // the chain only while this leaf's last entry still matched.
            $pageId = $sawMatch ? $this->nextLeafPageId($this->pages->read($pageId)) : null;
        }
    }

    private function prefixExists(string $prefix): bool
    {
        foreach ($this->scanPrefix($prefix) as $ignored) {
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Tree structure
    // -----------------------------------------------------------------

    private function initialize(): void
    {
        $meta = $this->pages->allocate(PageType::BTREE_META);
        $meta->insert(self::TAG_SPECIAL . pack('N', 1));
        $this->pages->write($meta);

        $this->writeLeaf($this->pages->allocate(PageType::BTREE_LEAF)->id, [], null);
    }

    private function rootPageId(): int
    {
        /** @var array{id: int} $unpacked */
        $unpacked = unpack('Nid', substr((string) $this->pages->read(0)->read(0), 1));

        return $unpacked['id'];
    }

    private function setRootPageId(int $id): void
    {
        $meta = $this->pages->read(0);
        $meta->update(0, self::TAG_SPECIAL . pack('N', $id));
        $this->pages->write($meta);
    }

    private function leftmostLeafPageId(): int
    {
        $pageId = $this->rootPageId();

        while ($this->pages->read($pageId)->type === PageType::BTREE_INTERNAL) {
            $pageId = $this->readInternalEntries($this->pages->read($pageId))[0]['child'];
        }

        return $pageId;
    }

    /** The leaf that would hold $key if it is anywhere in the tree. */
    private function leafPageIdFor(string $key): int
    {
        $pageId = $this->rootPageId();

        while (true) {
            $page = $this->pages->read($pageId);

            if ($page->type !== PageType::BTREE_INTERNAL) {
                return $pageId;
            }

            $entries = $this->readInternalEntries($page);
            $child = $entries[0]['child'];

            foreach ($entries as $entry) {
                if ($entry['key'] !== null && strcmp($entry['key'], $key) <= 0) {
                    $child = $entry['child'];
                }
            }

            $pageId = $child;
        }
    }

    /**
     * Inserts (or descends further to insert) $fullKey under the subtree
     * rooted at $pageId. Returns null when the node absorbed the change
     * without growing, or [$separatorKey, $newRightPageId] when it split
     * and the parent needs a new entry for the right half.
     *
     * @return array{0: string, 1: int}|null
     */
    private function insertIntoNode(int $pageId, string $fullKey): ?array
    {
        $page = $this->pages->read($pageId);

        if ($page->type === PageType::BTREE_LEAF) {
            if ($page->insert(self::TAG_ENTRY . $fullKey) !== null) {
                $this->pages->write($page);

                return null;
            }

            $entries = $this->readLeafEntries($page);
            $entries[] = $fullKey;
            usort($entries, strcmp(...));

            return $this->writeLeafWithSplit($pageId, $entries, $this->nextLeafPageId($page));
        }

        $entries = $this->readInternalEntries($page);
        $child = $entries[0]['child'];

        foreach ($entries as $entry) {
            if ($entry['key'] !== null && strcmp($entry['key'], $fullKey) <= 0) {
                $child = $entry['child'];
            }
        }

        $split = $this->insertIntoNode($child, $fullKey);

        if ($split === null) {
            return null;
        }

        [$separator, $newChildId] = $split;
        $entries[] = ['key' => $separator, 'child' => $newChildId];
        usort($entries, $this->internalEntryComparator(...));

        return $this->writeInternalWithSplit($pageId, $entries);
    }

    /**
     * @param array{0: string, 1: int} $split
     */
    private function growNewRoot(array $split): void
    {
        [$separator, $newRightId] = $split;
        $oldRoot = $this->rootPageId();

        $newRoot = $this->pages->allocate(PageType::BTREE_INTERNAL);
        $this->writeInternal($newRoot->id, [
            ['key' => null, 'child' => $oldRoot],
            ['key' => $separator, 'child' => $newRightId],
        ]);

        $this->setRootPageId($newRoot->id);
    }

    /**
     * @param array{key: ?string, child: int} $a
     * @param array{key: ?string, child: int} $b
     */
    private function internalEntryComparator(array $a, array $b): int
    {
        return match (true) {
            $a['key'] === null => -1,
            $b['key'] === null => 1,
            default => strcmp($a['key'], $b['key']),
        };
    }

    // -----------------------------------------------------------------
    // Node encoding
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function readLeafEntries(Page $page): array
    {
        $entries = [];

        foreach ($page->occupiedSlots() as $slot) {
            $record = (string) $page->read($slot);

            if ($record[0] !== self::TAG_SPECIAL) {
                $entries[] = substr($record, 1);
            }
        }

        usort($entries, strcmp(...));

        return $entries;
    }

    private function nextLeafPageId(Page $page): ?int
    {
        /** @var array{id: int} $unpacked */
        $unpacked = unpack('Nid', substr((string) $page->read(0), 1));

        return $unpacked['id'] === self::NO_PAGE ? null : $unpacked['id'];
    }

    /** @param list<string> $entries */
    private function writeLeaf(int $pageId, array $entries, ?int $nextLeafPageId): void
    {
        $page = Page::create($pageId, PageType::BTREE_LEAF);
        $page->insert(self::TAG_SPECIAL . pack('N', $nextLeafPageId ?? self::NO_PAGE));

        foreach ($entries as $entry) {
            $page->insert(self::TAG_ENTRY . $entry);
        }

        $this->pages->write($page);
    }

    /**
     * @param list<string> $entries
     *
     * @return array{0: string, 1: int}|null
     */
    private function writeLeafWithSplit(int $pageId, array $entries, ?int $nextLeafPageId): ?array
    {
        if ($this->fitsOnALeaf($entries)) {
            $this->writeLeaf($pageId, $entries, $nextLeafPageId);

            return null;
        }

        $mid = intdiv(count($entries), 2);
        $left = array_slice($entries, 0, $mid);
        $right = array_slice($entries, $mid);

        $rightPage = $this->pages->allocate(PageType::BTREE_LEAF);
        $this->writeLeaf($rightPage->id, $right, $nextLeafPageId);
        $this->writeLeaf($pageId, $left, $rightPage->id);

        return [$right[0], $rightPage->id];
    }

    /** @param list<string> $entries */
    private function fitsOnALeaf(array $entries): bool
    {
        $page = Page::create(0, PageType::BTREE_LEAF);
        $page->insert(self::TAG_SPECIAL . pack('N', 0));

        foreach ($entries as $entry) {
            if ($page->insert(self::TAG_ENTRY . $entry) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{key: ?string, child: int}>
     */
    private function readInternalEntries(Page $page): array
    {
        $entries = [];

        foreach ($page->occupiedSlots() as $slot) {
            $record = (string) $page->read($slot);
            $isSentinel = $record[0] === self::TAG_SPECIAL;

            /** @var array{child: int} $unpacked */
            $unpacked = unpack('Nchild', substr($record, -4));
            $entries[] = ['key' => $isSentinel ? null : substr($record, 1, -4), 'child' => $unpacked['child']];
        }

        usort($entries, $this->internalEntryComparator(...));

        return $entries;
    }

    /** @param list<array{key: ?string, child: int}> $entries */
    private function writeInternal(int $pageId, array $entries): void
    {
        $page = Page::create($pageId, PageType::BTREE_INTERNAL);

        foreach ($entries as $entry) {
            $tag = $entry['key'] === null ? self::TAG_SPECIAL : self::TAG_ENTRY . $entry['key'];
            $page->insert($tag . pack('N', $entry['child']));
        }

        $this->pages->write($page);
    }

    /**
     * @param list<array{key: ?string, child: int}> $entries
     *
     * @return array{0: string, 1: int}|null
     */
    private function writeInternalWithSplit(int $pageId, array $entries): ?array
    {
        if ($this->fitsOnAnInternalPage($entries)) {
            $this->writeInternal($pageId, $entries);

            return null;
        }

        $mid = intdiv(count($entries), 2);
        $left = array_slice($entries, 0, $mid);
        $promoted = $entries[$mid];
        $right = array_slice($entries, $mid + 1);
        array_unshift($right, ['key' => null, 'child' => $promoted['child']]);

        $rightPage = $this->pages->allocate(PageType::BTREE_INTERNAL);
        $this->writeInternal($rightPage->id, $right);
        $this->writeInternal($pageId, $left);

        return [$promoted['key'], $rightPage->id];
    }

    /** @param list<array{key: ?string, child: int}> $entries */
    private function fitsOnAnInternalPage(array $entries): bool
    {
        $page = Page::create(0, PageType::BTREE_INTERNAL);

        foreach ($entries as $entry) {
            $tag = $entry['key'] === null ? self::TAG_SPECIAL : self::TAG_ENTRY . $entry['key'];

            if ($page->insert($tag . pack('N', $entry['child'])) === null) {
                return false;
            }
        }

        return true;
    }
}

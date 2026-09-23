<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use Closure;
use Generator;
use PhpMiniDatabase\Infrastructure\FileSystem;
use Throwable;

/**
 * An unordered file of records — the table itself, once a row has been
 * serialized. It knows nothing about columns or types: a record is a string,
 * and RecordSerializer decides what the string means.
 *
 * "Heap" is the property, not just the name: rows are stored wherever there
 * is room and in no particular order. Finding a row by value means reading
 * every page (a sequential scan) until an index exists to say which page to
 * look at, which is exactly the problem Phase 6 solves.
 *
 * ### Where an insert goes
 *
 * Into the last page, or into a new one if that is full. That is the whole
 * strategy. The consequence is that space freed by DELETE is not handed back
 * to the next INSERT — a page in the middle of the file with room in it will
 * not be found — and the file only shrinks when vacuum() rewrites it.
 *
 * The alternative is a free-space map: a record of how much room each page
 * has, consulted on every insert and updated on every delete. It is the
 * right answer for a database under a delete-heavy workload, and it is a
 * second structure to keep consistent with the pages themselves, including
 * across a crash. Postponing it costs a VACUUM; adopting it early would cost
 * correctness arguments in every phase after this one. The seam is
 * insert()'s choice of page, and nothing above this class would notice.
 */
final class HeapFile
{
    /** The page insert() tries first: always the last one in the file. */
    private int $insertHint;

    public function __construct(
        // Not readonly: vacuum() replaces the file underneath and reopens it.
        private PageManager $pages,
        private readonly FileSystem $files = new FileSystem(),
    ) {
        $this->insertHint = max(0, $pages->pageCount() - 1);
    }

    public static function open(string $path, FileSystem $files = new FileSystem()): self
    {
        return new self(new PageManager($path, $files), $files);
    }

    public function insert(string $record): RecordId
    {
        $page = $this->pages->pageCount() === 0
            ? $this->pages->allocate(PageType::HEAP)
            : $this->pages->read($this->insertHint);

        $slot = $page->insert($record);

        if ($slot === null) {
            $page = $this->pages->allocate(PageType::HEAP);
            // A record too large for an empty page cannot be stored at all,
            // and insert() says so by throwing rather than returning null.
            $slot = (int) $page->insert($record);
        }

        $this->pages->write($page);
        $this->insertHint = $page->id;

        return new RecordId($page->id, $slot);
    }

    /**
     * How many pages the file currently has — an O(1) proxy for the
     * table's size, cheap enough for `Sql\Optimizer\Rule\JoinReordering`
     * to call while planning a query, unlike counting rows via `scan()`.
     */
    public function pageCount(): int
    {
        return $this->pages->pageCount();
    }

    /** The record, or null if that slot has been deleted. */
    public function read(RecordId $id): ?string
    {
        return $this->pages->read($id->pageId)->read($id->slot);
    }

    /**
     * Replace a record, in place where it fits.
     *
     * Returns the record's id afterwards, which is *not* always the one
     * passed in: a record that grew beyond what its page can hold is moved,
     * and moving is what changes the id. Callers holding references to the
     * row — indexes, above all — have to follow the returned id rather than
     * assume the old one.
     */
    public function update(RecordId $id, string $record): RecordId
    {
        $page = $this->pages->read($id->pageId);

        if ($page->update($id->slot, $record)) {
            $this->pages->write($page);

            return $id;
        }

        $page->delete($id->slot);
        $this->pages->write($page);

        return $this->insert($record);
    }

    /**
     * Puts a deleted record back at the id it had, if that slot is still
     * free and it still fits — see `Page::restore()`. Falls back to an
     * ordinary `insert()`, and therefore a new id, when it cannot;
     * `Execution\Executor::undoDelete()` is the caller that cares.
     */
    public function restore(RecordId $id, string $record): RecordId
    {
        if ($id->pageId >= $this->pages->pageCount()) {
            return $this->insert($record);
        }

        $page = $this->pages->read($id->pageId);

        if (!$page->restore($id->slot, $record)) {
            return $this->insert($record);
        }

        $this->pages->write($page);

        return $id;
    }

    public function delete(RecordId $id): void
    {
        $page = $this->pages->read($id->pageId);
        $page->delete($id->slot);

        $this->pages->write($page);
    }

    /**
     * Every live record in the file, in physical order, one page at a time.
     *
     * A generator rather than an array: a table is allowed to be larger than
     * memory, and every operator above this one — SeqScan, and through it
     * every SELECT without an index — consumes rows one at a time. The keys
     * are RecordIds, so a caller that wants to update or delete what it is
     * reading already has the address.
     *
     * @return Generator<RecordId, string>
     */
    public function scan(): Generator
    {
        for ($pageId = 0; $pageId < $this->pages->pageCount(); $pageId++) {
            $page = $this->pages->read($pageId);

            if ($page->type !== PageType::HEAP) {
                continue;
            }

            foreach ($page->occupiedSlots() as $slot) {
                yield new RecordId($pageId, $slot) => (string) $page->read($slot);
            }
        }
    }

    /**
     * Rewrite the file with the deleted records dropped and the survivors
     * packed tight, and return how many bytes that gave back.
     *
     * **Every RecordId in the file changes.** Nothing else here moves a
     * record without telling its caller, but vacuum moves all of them at
     * once, so anything that stores record ids — every index on the table —
     * has to be rebuilt afterwards. That is the price of not keeping a
     * forwarding map, which would have to be as large as the table.
     *
     * The rewrite goes to a temporary file that is renamed over the original
     * at the end, so a crash halfway through leaves the original intact and
     * loses only the temporary.
     */
    public function vacuum(): int
    {
        $path = $this->pages->path();
        $sizeBefore = $this->files->size($path);

        $this->replace('.vacuum', static fn (string $record): string => $record);

        return $sizeBefore - $this->files->size($path);
    }

    /**
     * Put every live record through $transform and keep the results in
     * place of what is here now — how `ALTER TABLE` re-encodes a whole
     * table once a column has been added to or removed from its schema,
     * since a record carries no schema of its own to decode it by (see
     * `RecordSerializer`).
     *
     * **Every RecordId in the file changes**, exactly as in `vacuum()`,
     * and for the same reason: this is the same rewrite, with the
     * identity transform swapped for a real one. Every index on the table
     * has to be rebuilt afterwards.
     *
     * A transform that throws — a row the new schema refuses — aborts the
     * whole rewrite with this file untouched, so a half-converted table is
     * never what a failure leaves behind.
     *
     * @param Closure(string): string $transform
     */
    public function rewrite(Closure $transform): void
    {
        $this->replace('.rewrite', $transform);
    }

    /**
     * The shared machinery of `vacuum()` and `rewrite()`: build the whole
     * new file under a temporary name, then rename it over the original
     * and reopen. A crash before the rename leaves the original intact and
     * loses only the temporary; a throw partway through deletes the
     * temporary on the way out, so a retry does not reopen half a file.
     *
     * @param Closure(string): string $transform
     */
    private function replace(string $suffix, Closure $transform): void
    {
        $path = $this->pages->path();
        $temporary = $path . $suffix;

        $this->files->delete($temporary);
        $rewritten = self::open($temporary, $this->files);

        try {
            foreach ($this->scan() as $record) {
                $rewritten->insert($transform($record));
            }

            $rewritten->sync();
        } catch (Throwable $e) {
            $rewritten->close();
            $this->files->delete($temporary);

            throw $e;
        }

        $rewritten->close();

        $this->pages->close();
        $this->files->rename($temporary, $path);
        $this->pages = new PageManager($path, $this->files);
        $this->insertHint = max(0, $this->pages->pageCount() - 1);
    }

    public function sync(): void
    {
        $this->pages->sync();
    }

    public function close(): void
    {
        $this->pages->close();
    }
}

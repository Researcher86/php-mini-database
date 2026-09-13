<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Infrastructure\FileSystem;

/**
 * Reads and writes whole pages of one file, addressed by page number.
 *
 * The mapping is the simplest one that can work: page N occupies bytes
 * [N * 8192, (N + 1) * 8192). Nothing else is needed, because the page
 * carries its own id and type in its header, so the file needs no index of
 * its own contents and a page can be located with arithmetic rather than a
 * lookup.
 *
 * There is no buffer pool here, on purpose. read() returns a freshly decoded
 * Page every time and write() puts one back. A cache would have to track
 * which cached pages are dirty, write them out on eviction, and hand the
 * same mutable Page to two callers who both think they own it — three
 * sources of bugs bought in exchange for an optimisation that is worth
 * measuring before it is worth having. The seam for one is this class, and
 * no caller would change.
 */
final class PageManager
{
    /** @var resource */
    private mixed $handle;

    private int $pageCount;

    public function __construct(
        private readonly string $path,
        private readonly FileSystem $files = new FileSystem(),
    ) {
        $this->handle = $this->files->openReadWrite($path);
        $size = $this->files->size($path);

        if ($size % Page::SIZE !== 0) {
            throw new StorageException(sprintf(
                'File "%s" is %d bytes, not a whole number of %d byte pages.',
                $path,
                $size,
                Page::SIZE,
            ));
        }

        $this->pageCount = intdiv($size, Page::SIZE);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function pageCount(): int
    {
        return $this->pageCount;
    }

    public function read(int $pageId): Page
    {
        if ($pageId < 0 || $pageId >= $this->pageCount) {
            throw new StorageException(sprintf('Page %d is outside "%s" (%d pages).', $pageId, $this->path, $this->pageCount));
        }

        $this->seek($pageId);

        $bytes = '';

        // A single fread() can return fewer bytes than asked for even on a
        // local file; loop until the page is whole rather than handing a
        // short buffer to Page::fromBytes() and calling the file corrupt.
        while (strlen($bytes) < Page::SIZE) {
            $chunk = fread($this->handle, Page::SIZE - strlen($bytes));

            if ($chunk === false || $chunk === '') {
                throw new StorageException(sprintf('Page %d of "%s" ends early.', $pageId, $this->path));
            }

            $bytes .= $chunk;
        }

        return Page::fromBytes($pageId, $bytes);
    }

    public function write(Page $page): void
    {
        if ($page->id < 0 || $page->id > $this->pageCount) {
            throw new StorageException(sprintf('Cannot write page %d: "%s" has %d pages.', $page->id, $this->path, $this->pageCount));
        }

        $this->seek($page->id);

        if (fwrite($this->handle, $page->toBytes()) !== Page::SIZE) {
            throw new StorageException(sprintf('Short write of page %d to "%s".', $page->id, $this->path));
        }

        // Writing at pageCount extends the file by exactly one page, which
        // is how allocate() materialises the page it just handed out.
        $this->pageCount = max($this->pageCount, $page->id + 1);
    }

    /**
     * Hand out the next page number. The page is only in memory until it is
     * written — a caller that allocates and then fails leaves the file
     * exactly as it was.
     */
    public function allocate(PageType $type): Page
    {
        return Page::create($this->pageCount, $type);
    }

    /** Push everything written so far all the way to the device. */
    public function sync(): void
    {
        if (!fflush($this->handle) || !fsync($this->handle)) {
            throw new StorageException(sprintf('Cannot sync "%s".', $this->path));
        }
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    private function seek(int $pageId): void
    {
        if (fseek($this->handle, $pageId * Page::SIZE) !== 0) {
            throw new StorageException(sprintf('Cannot seek to page %d of "%s".', $pageId, $this->path));
        }
    }
}

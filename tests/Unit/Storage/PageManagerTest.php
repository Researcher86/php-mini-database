<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Storage;

use MiniDatabase\Exception\StorageException;
use MiniDatabase\Storage\Page;
use MiniDatabase\Storage\PageManager;
use MiniDatabase\Storage\PageType;
use MiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class PageManagerTest extends TestCase
{
    use TemporaryDirectory;

    private PageManager $pages;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->pages = new PageManager($this->path('heap.dat'));
    }

    protected function tearDown(): void
    {
        $this->pages->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testANewFileHasNoPages(): void
    {
        self::assertSame(0, $this->pages->pageCount());
    }

    public function testAllocatedPagesAreNumberedInOrderAndOnlyCountOnceWritten(): void
    {
        $first = $this->pages->allocate(PageType::HEAP);

        self::assertSame(0, $first->id);
        self::assertSame(0, $this->pages->pageCount(), 'allocate() alone must not touch the file');

        $this->pages->write($first);

        self::assertSame(1, $this->pages->pageCount());
        self::assertSame(1, $this->pages->allocate(PageType::HEAP)->id);
    }

    public function testAWrittenPageReadsBackWithItsContents(): void
    {
        $page = $this->pages->allocate(PageType::HEAP);
        $page->insert('hello');
        $this->pages->write($page);

        self::assertSame('hello', $this->pages->read(0)->read(0));
    }

    public function testPagesLandAtTheirOwnOffsetInTheFile(): void
    {
        foreach (['zero', 'one', 'two'] as $contents) {
            $page = $this->pages->allocate(PageType::HEAP);
            $page->insert($contents);
            $this->pages->write($page);
        }

        self::assertSame(3 * Page::SIZE, filesize($this->path('heap.dat')));
        self::assertSame('one', $this->pages->read(1)->read(0));
        self::assertSame('two', $this->pages->read(2)->read(0));
    }

    public function testContentsSurviveReopening(): void
    {
        $page = $this->pages->allocate(PageType::BTREE_LEAF);
        $page->insert('durable');
        $this->pages->write($page);
        $this->pages->sync();
        $this->pages->close();

        $this->pages = new PageManager($this->path('heap.dat'));

        self::assertSame(1, $this->pages->pageCount());
        self::assertSame(PageType::BTREE_LEAF, $this->pages->read(0)->type);
        self::assertSame('durable', $this->pages->read(0)->read(0));
    }

    public function testReadingBeyondTheEndIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->pages->read(0);
    }

    /**
     * Writing at pageCount extends the file by one page — that is how
     * allocate() materialises. Writing further out would leave a hole the
     * reader would parse as a corrupt page, so it is refused.
     */
    public function testWritingPastTheEndOfTheFileIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->pages->write(Page::create(3, PageType::HEAP));
    }

    public function testAFileThatIsNotAWholeNumberOfPagesIsRejected(): void
    {
        file_put_contents($this->path('ragged.dat'), str_repeat("\x00", Page::SIZE + 17));

        $this->expectException(StorageException::class);
        new PageManager($this->path('ragged.dat'));
    }
}

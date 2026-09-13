<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Infrastructure;

use MiniDatabase\Exception\StorageException;
use MiniDatabase\Infrastructure\AtomicWriter;
use MiniDatabase\Infrastructure\FileSystem;
use MiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class AtomicWriterTest extends TestCase
{
    use TemporaryDirectory;

    private AtomicWriter $writer;
    private FileSystem $files;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->files = new FileSystem();
        $this->writer = new AtomicWriter($this->files);
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testWritesANewFile(): void
    {
        $this->writer->write($this->path('catalog.json'), '{"tables":[]}');

        self::assertSame('{"tables":[]}', $this->files->read($this->path('catalog.json')));
    }

    public function testReplacesAnExistingFileWhole(): void
    {
        $this->files->write($this->path('catalog.json'), 'old and longer contents');

        $this->writer->write($this->path('catalog.json'), 'new');

        self::assertSame('new', $this->files->read($this->path('catalog.json')));
    }

    public function testLeavesNoTemporaryFileBehind(): void
    {
        $this->writer->write($this->path('catalog.json'), 'x');

        self::assertSame(['catalog.json'], $this->files->listDirectory($this->path('')));
    }

    public function testFilesAreOwnerOnlyByDefault(): void
    {
        $this->writer->write($this->path('users.json'), '{}');

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->path('users.json'))), -4));
    }

    /**
     * A failure must not leave the target half-written *or* a stray
     * temporary next to it — the directory has to look exactly as it did.
     */
    public function testAFailedWriteLeavesTheDirectoryUntouched(): void
    {
        $this->files->write($this->path('catalog.json'), 'original');

        try {
            $this->writer->write($this->path('no-such-directory/catalog.json'), 'never written');
            self::fail('Expected the write to fail.');
        } catch (StorageException) {
            // expected
        }

        self::assertSame(['catalog.json'], $this->files->listDirectory($this->path('')));
        self::assertSame('original', $this->files->read($this->path('catalog.json')));
    }

    public function testHandlesBinaryContentAndEmptyFiles(): void
    {
        $this->writer->write($this->path('binary'), "\x00\xff\x00");
        $this->writer->write($this->path('empty'), '');

        self::assertSame("\x00\xff\x00", $this->files->read($this->path('binary')));
        self::assertSame('', $this->files->read($this->path('empty')));
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Infrastructure;

use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class FileSystemTest extends TestCase
{
    use TemporaryDirectory;

    private FileSystem $files;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->files = new FileSystem();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testWriteThenRead(): void
    {
        $this->files->write($this->path('a.txt'), 'contents');

        self::assertSame('contents', $this->files->read($this->path('a.txt')));
        self::assertSame(8, $this->files->size($this->path('a.txt')));
    }

    public function testFilesAreOwnerOnlyByDefault(): void
    {
        $this->files->write($this->path('secret.json'), '{}');

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->path('secret.json'))), -4));
    }

    public function testEnsureDirectoryCreatesNestedPathsAndIsIdempotent(): void
    {
        $nested = $this->path('a/b/c');

        $this->files->ensureDirectory($nested);
        $this->files->ensureDirectory($nested);

        self::assertTrue($this->files->isDirectory($nested));
    }

    public function testDeleteIsForgivingOfAMissingFile(): void
    {
        $this->files->delete($this->path('never-existed'));

        self::assertFalse($this->files->exists($this->path('never-existed')));
    }

    public function testListDirectoryOmitsDotEntries(): void
    {
        $this->files->write($this->path('one'), '');
        $this->files->write($this->path('two'), '');

        $entries = $this->files->listDirectory($this->path(''));
        sort($entries);

        self::assertSame(['one', 'two'], $entries);
    }

    public function testRemoveDirectoryTakesItsContentsWithIt(): void
    {
        $this->files->ensureDirectory($this->path('tree/nested'));
        $this->files->write($this->path('tree/file'), 'x');
        $this->files->write($this->path('tree/nested/file'), 'y');

        $this->files->removeDirectory($this->path('tree'));

        self::assertFalse($this->files->exists($this->path('tree')));
    }

    public function testRenameMovesTheFile(): void
    {
        $this->files->write($this->path('from'), 'moved');

        $this->files->rename($this->path('from'), $this->path('to'));

        self::assertFalse($this->files->exists($this->path('from')));
        self::assertSame('moved', $this->files->read($this->path('to')));
    }

    /**
     * The reason this class exists: a missing file is an exception with the
     * path in it, not a `false` the caller has to remember to check.
     */
    public function testFailuresBecomeStorageExceptions(): void
    {
        $this->expectException(StorageException::class);
        $this->files->read($this->path('missing'));
    }

    public function testOpenReadWriteCreatesWithoutTruncating(): void
    {
        $this->files->write($this->path('existing'), 'kept');

        $handle = $this->files->openReadWrite($this->path('existing'));
        fclose($handle);

        self::assertSame('kept', $this->files->read($this->path('existing')));
    }
}

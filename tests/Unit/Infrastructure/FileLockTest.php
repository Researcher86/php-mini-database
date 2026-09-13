<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Infrastructure;

use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Infrastructure\FileLock;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Two FileLock instances contend even inside one process: `flock()` locks an
 * open file description, and each instance opens the file itself. That is
 * what makes these tests possible without forking — and it is also the
 * behaviour the server depends on, since one process must not be able to
 * take the same lock twice by accident.
 */
final class FileLockTest extends TestCase
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

    public function testAnExclusiveLockIsHeldUntilReleased(): void
    {
        $lock = new FileLock($this->path('db.lock'));

        $lock->acquireExclusive();

        self::assertTrue($lock->isHeld());

        $lock->release();

        self::assertFalse($lock->isHeld());
    }

    public function testASecondExclusiveLockTimesOut(): void
    {
        $held = new FileLock($this->path('db.lock'));
        $held->acquireExclusive();

        $waiting = new FileLock($this->path('db.lock'));

        $this->expectException(StorageException::class);

        try {
            $waiting->acquireExclusive(timeoutSeconds: 0.05);
        } finally {
            $held->release();
        }
    }

    public function testTheLockIsAvailableAgainAfterRelease(): void
    {
        $first = new FileLock($this->path('db.lock'));
        $first->acquireExclusive();
        $first->release();

        $second = new FileLock($this->path('db.lock'));
        $second->acquireExclusive(timeoutSeconds: 0.05);

        self::assertTrue($second->isHeld());

        $second->release();
    }

    public function testSharedLocksCoexist(): void
    {
        $first = new FileLock($this->path('db.lock'));
        $second = new FileLock($this->path('db.lock'));

        $first->acquireShared();
        $second->acquireShared(timeoutSeconds: 0.05);

        self::assertTrue($first->isHeld());
        self::assertTrue($second->isHeld());

        $first->release();
        $second->release();
    }

    public function testAWriterWaitsForAReader(): void
    {
        $reader = new FileLock($this->path('db.lock'));
        $reader->acquireShared();

        $writer = new FileLock($this->path('db.lock'));

        $this->expectException(StorageException::class);

        try {
            $writer->acquireExclusive(timeoutSeconds: 0.05);
        } finally {
            $reader->release();
        }
    }

    public function testTakingTheSameLockTwiceIsAProgrammingError(): void
    {
        $lock = new FileLock($this->path('db.lock'));
        $lock->acquireExclusive();

        try {
            $this->expectException(StorageException::class);
            $lock->acquireExclusive();
        } finally {
            $lock->release();
        }
    }

    public function testReleasingALockThatWasNeverTakenIsHarmless(): void
    {
        $lock = new FileLock($this->path('db.lock'));

        $lock->release();

        self::assertFalse($lock->isHeld());
    }
}

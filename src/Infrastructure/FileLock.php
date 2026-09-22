<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Infrastructure;

use PhpMiniDatabase\Exception\StorageException;

/**
 * An advisory lock on a lock file, used to keep two processes from writing
 * the same database at once.
 *
 * `flock()` is advisory: it constrains only processes that ask for the same
 * lock. That is enough here, because every process that touches the data
 * goes through this class — and it is all that is portable, since mandatory
 * locking is a Linux-specific mount option.
 *
 * The lock is held on a dedicated `.lock` file rather than on the data file
 * itself. A data file can be replaced by rename (see AtomicWriter), and the
 * lock would then be held on an unlinked inode nobody else can reach; a lock
 * file is never replaced, only locked.
 *
 * Waiting is done by polling a non-blocking `flock()` rather than by
 * blocking in it, so a caller can put a bound on how long it waits. A
 * blocking flock() cannot be interrupted by a timeout, and a server that
 * blocks forever on a lock held by a crashed process is worse than one that
 * reports it cannot get the lock.
 */
final class FileLock
{
    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(
        private readonly string $path,
        private readonly FileSystem $files = new FileSystem(),
        private readonly float $pollIntervalSeconds = 0.01,
    ) {
    }

    /** Exclusive: one writer, no readers. */
    public function acquireExclusive(float $timeoutSeconds = 5.0): void
    {
        $this->acquire(LOCK_EX, $timeoutSeconds, 'exclusive');
    }

    /** Shared: any number of readers, no writer. */
    public function acquireShared(float $timeoutSeconds = 5.0): void
    {
        $this->acquire(LOCK_SH, $timeoutSeconds, 'shared');
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    /** @param int<1, 2> $operation `LOCK_EX` or `LOCK_SH` — `LOCK_NB` is ORed in below, never passed in. */
    private function acquire(int $operation, float $timeoutSeconds, string $kind): void
    {
        if ($this->handle !== null) {
            throw new StorageException(sprintf('Lock "%s" is already held by this process.', $this->path));
        }

        $handle = $this->files->openReadWrite($this->path);
        $deadline = microtime(true) + $timeoutSeconds;

        while (!flock($handle, $operation | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new StorageException(sprintf(
                    'Timed out after %.1fs waiting for the %s lock on "%s".',
                    $timeoutSeconds,
                    $kind,
                    $this->path,
                ));
            }

            usleep((int) ($this->pollIntervalSeconds * 1_000_000));
        }

        $this->handle = $handle;
    }

    /**
     * Releasing on destruction is a safety net, not the contract: the kernel
     * drops the lock when the process exits anyway. It matters for the case
     * in between — a long-lived server that drops its last reference to a
     * lock without having released it — where the lock would otherwise be
     * held until the process ended.
     */
    public function __destruct()
    {
        $this->release();
    }
}

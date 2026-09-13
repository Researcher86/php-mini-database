<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Infrastructure;

use PhpMiniDatabase\Exception\StorageException;

/**
 * Replaces a file's contents in one step, or not at all.
 *
 * A plain `file_put_contents()` truncates first and writes after: a crash
 * between the two leaves an empty catalog, and a reader that opens the file
 * in the meantime sees a half-written one. Neither is acceptable for the
 * files that describe the database to itself — `catalog.json`,
 * `schema.json`, `users.json`.
 *
 * The fix is the standard one: write a temporary file beside the target,
 * flush it all the way to the disk, then rename it over the target. rename()
 * within a directory is atomic on POSIX, so a reader sees either the whole
 * old file or the whole new one, and a crash can only lose the temporary.
 *
 * The temporary is created in the *same directory* as the target on purpose.
 * rename() is only atomic within a filesystem, and the system temp directory
 * is often a different one.
 */
final readonly class AtomicWriter
{
    public function __construct(
        private FileSystem $files = new FileSystem(),
    ) {
    }

    public function write(string $path, string $contents, int $mode = 0600): void
    {
        $temporary = $path . '.tmp.' . getmypid();

        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw new StorageException(sprintf('Cannot create temporary file for "%s".', $path));
        }

        try {
            if (@fwrite($handle, $contents) !== strlen($contents)) {
                throw new StorageException(sprintf('Cannot write temporary file for "%s".', $path));
            }

            // fflush() only pushes PHP's buffer into the kernel; fsync() is
            // what pushes the kernel's into the device. Without it the
            // rename can reach the disk before the data it is meant to
            // publish, and a crash in that window leaves the new name
            // pointing at an empty file - the exact failure this class
            // exists to prevent.
            if (!@fflush($handle) || !@fsync($handle)) {
                throw new StorageException(sprintf('Cannot flush temporary file for "%s".', $path));
            }
        } catch (StorageException $e) {
            fclose($handle);
            $this->files->delete($temporary);

            throw $e;
        }

        fclose($handle);

        @chmod($temporary, $mode);

        try {
            $this->files->rename($temporary, $path);
        } catch (StorageException $e) {
            $this->files->delete($temporary);

            throw $e;
        }
    }
}

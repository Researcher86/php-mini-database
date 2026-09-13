<?php

declare(strict_types=1);

namespace MiniDatabase\Infrastructure;

use MiniDatabase\Exception\StorageException;

/**
 * The database's one door to the filesystem.
 *
 * PHP's file functions report failure by returning false and raising a
 * warning, which is the opposite of how the rest of this codebase reports
 * failure. Every call is funnelled through here so that translation happens
 * once: `@` to silence the warning, an explicit check, and a
 * StorageException carrying the path that failed. A caller never has to
 * remember which of these functions returns false and which returns null.
 *
 * Permissions are owner-only by default (0600 for files, 0700 for
 * directories). A database file holds everything the server knows, including
 * password hashes; the default should be the safe one, and a deployment that
 * wants it shared has to say so.
 */
final class FileSystem
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function ensureDirectory(string $path, int $mode = 0700): void
    {
        if (is_dir($path)) {
            return;
        }

        // The recursive mkdir can lose a race with another process creating
        // the same directory, so a failure is only a failure if the
        // directory still isn't there afterwards.
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new StorageException(sprintf('Cannot create directory "%s".', $path));
        }
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new StorageException(sprintf('Cannot read "%s".', $path));
        }

        return $contents;
    }

    /**
     * A plain, non-atomic write. Use AtomicWriter for anything a reader may
     * be looking at concurrently or that must survive a crash mid-write.
     */
    public function write(string $path, string $contents, int $mode = 0600): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new StorageException(sprintf('Cannot write "%s".', $path));
        }

        @chmod($path, $mode);
    }

    public function delete(string $path): void
    {
        if (file_exists($path) && !@unlink($path)) {
            throw new StorageException(sprintf('Cannot delete "%s".', $path));
        }
    }

    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new StorageException(sprintf('Cannot rename "%s" to "%s".', $from, $to));
        }
    }

    public function size(string $path): int
    {
        $size = @filesize($path);

        if ($size === false) {
            throw new StorageException(sprintf('Cannot stat "%s".', $path));
        }

        return $size;
    }

    /**
     * Entries directly inside a directory, sorted, with "." and ".." removed.
     *
     * @return list<string> names, not paths
     */
    public function listDirectory(string $path): array
    {
        $entries = @scandir($path);

        if ($entries === false) {
            throw new StorageException(sprintf('Cannot list "%s".', $path));
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    /**
     * Remove a directory and everything under it. Used by DROP TABLE and by
     * tests; deliberately not recursive over symlinks, which it deletes as
     * links rather than following.
     */
    public function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ($this->listDirectory($path) as $entry) {
            $child = Path::join($path, $entry);

            is_dir($child) && !is_link($child)
                ? $this->removeDirectory($child)
                : $this->delete($child);
        }

        if (!@rmdir($path)) {
            throw new StorageException(sprintf('Cannot remove directory "%s".', $path));
        }
    }

    /**
     * Open a file for binary read/write, creating it if missing.
     *
     * @return resource
     */
    public function openReadWrite(string $path, int $mode = 0600): mixed
    {
        $isNew = !file_exists($path);

        // "c+" rather than "r+" so the file is created when absent, and
        // rather than "w+" so an existing one is not truncated. It also
        // leaves the cursor at 0 without seeking.
        $handle = @fopen($path, 'c+b');

        if ($handle === false) {
            throw new StorageException(sprintf('Cannot open "%s".', $path));
        }

        if ($isNew) {
            @chmod($path, $mode);
        }

        return $handle;
    }
}

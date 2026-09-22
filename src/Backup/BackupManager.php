<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Backup;

use Phar;
use PharData;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Infrastructure\FileSystem;

/**
 * PLAN.md §11 Milestone 19's "tar.gz backups" — a whole data directory,
 * archived and restored as one file, independent of `Dumper`/`Restorer`'s
 * logical (SQL) backup: this is a physical one, every file on disk
 * exactly as it was, WAL and catalog included.
 *
 * `PharData` (`ext-phar`) builds and reads the archive — no shelling out
 * to a `tar` binary, consistent with this project generally preferring a
 * PHP-native mechanism over an external process where one exists
 * (`stream_socket_*` instead of a network tool, `pcntl`/`posix` instead
 * of shelling out to process-management commands). `PharData` works
 * regardless of `phar.readonly` (verified directly — that ini setting
 * only restricts *executable* `.phar` archives, not plain `.tar`/`.tar.gz`
 * ones), so no php.ini change is required to use it.
 *
 * `backup()` always builds the `.tar.gz` beside the requested
 * `$archivePath` first and `rename()`s it into place last — the same
 * "write beside, then atomically rename over the target" shape
 * `Infrastructure\AtomicWriter` already uses for the same reason: a
 * reader must never see a half-written archive.
 *
 * There is no attempt at a *consistent* hot backup here: this project has
 * no snapshot isolation for its own files (Phase 2 chose no buffer pool,
 * and nothing since added MVCC), so archiving a data directory a running
 * server is actively writing to can capture files mid-change. A named
 * gap, not a silent one — the caller decides whether to stop the server
 * first.
 *
 * `restore()` refuses a non-empty target directory unless `$force` is
 * `true` — `PharData::extractTo()`'s own overwrite behavior would
 * otherwise silently merge an archive's files into whatever is already
 * there, which for a *database's* data directory means mixing two
 * different databases' files together rather than either one, cleanly.
 */
final class BackupManager
{
    public function __construct(
        private readonly FileSystem $files = new FileSystem(),
    ) {
    }

    public function backup(string $dataDirectory, string $archivePath): void
    {
        if (!is_dir($dataDirectory)) {
            throw new StorageException(sprintf('"%s" is not a directory.', $dataDirectory));
        }

        $this->files->ensureDirectory(dirname($archivePath));

        $tarPath = $archivePath . '.tmp.' . getmypid() . '.tar';
        $gzPath = $tarPath . '.gz';
        @unlink($tarPath);
        @unlink($gzPath);

        $archive = new PharData($tarPath, 0, null, Phar::TAR);
        $archive->buildFromDirectory($dataDirectory);

        // A brand new, never-used-for-a-CREATE-TABLE data directory has
        // nothing in it at all yet (see DECISIONS.md) - buildFromDirectory()
        // then adds zero entries, and PharData silently never writes the
        // tar file to disk at all, which would make everything below fail.
        // One harmless placeholder entry is enough to make it commit a
        // real (if nearly empty) archive; Database::open() recreates
        // whatever real directory structure it needs from nothing anyway,
        // so losing this file on restore costs nothing.
        if (count($archive) === 0) {
            $archive->addFromString('.minidb-empty-backup', '');
        }

        $archive->compress(Phar::GZ);
        unset($archive);

        unlink($tarPath);
        rename($gzPath, $archivePath);
    }

    public function restore(string $archivePath, string $dataDirectory, bool $force = false): void
    {
        if (!is_file($archivePath)) {
            throw new StorageException(sprintf('"%s" is not a file.', $archivePath));
        }

        if (!$force && is_dir($dataDirectory) && $this->hasEntries($dataDirectory)) {
            throw new StorageException(sprintf(
                '"%s" already has files in it; refusing to restore over it without $force.',
                $dataDirectory,
            ));
        }

        $this->files->ensureDirectory($dataDirectory);

        $archive = new PharData($archivePath, 0, null, Phar::TAR);
        $archive->extractTo($dataDirectory, null, true);
    }

    private function hasEntries(string $directory): bool
    {
        $entries = array_diff(scandir($directory) ?: [], ['.', '..']);

        return $entries !== [];
    }
}

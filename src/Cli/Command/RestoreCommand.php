<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Backup\BackupManager;
use Throwable;

/**
 * `bin/minidb restore --archive <archive.tar.gz> --data <dir>` —
 * `BackupCommand`'s counterpart. See that class's own docblock for why
 * this is a local filesystem operation, not a network one.
 *
 * `--force` is required to restore into a data directory that already
 * has files in it — `Backup\BackupManager::restore()`'s own refusal,
 * surfaced here rather than silently passed `force: true` always, so a
 * caller has to mean it before two databases' files end up mixed
 * together in one directory.
 */
final class RestoreCommand
{
    /**
     * @param array<string, list<string>> $options
     * @param array<string, bool>         $flags
     * @param resource                    $output
     * @param resource                    $errorOutput
     */
    public function run(array $options, array $flags, mixed $output, mixed $errorOutput): int
    {
        $archivePath = $options['archive'][0] ?? null;
        $dataDirectory = $options['data'][0] ?? null;

        if ($archivePath === null || $dataDirectory === null) {
            fwrite($errorOutput, "restore requires --archive <file> and --data <dir>.\n");

            return 1;
        }

        try {
            (new BackupManager())->restore($archivePath, $dataDirectory, force: $flags['force'] ?? false);
        } catch (Throwable $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        fwrite($output, sprintf("Restored \"%s\" to \"%s\".\n", $archivePath, $dataDirectory));

        return 0;
    }
}

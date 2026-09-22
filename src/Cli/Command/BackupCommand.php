<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Backup\BackupManager;
use Throwable;

/**
 * `bin/minidb backup --data <dir> --output <archive.tar.gz>` — PLAN.md
 * §9.2/§11 Milestone 19: a physical, tar.gz copy of a whole data
 * directory via `Backup\BackupManager`.
 *
 * This is a local filesystem operation, the same as `Cli\Command\UserCommand`
 * — it never opens a `Client\Connection`, since there is nothing a
 * network round trip would add over reading the files directly, and no
 * wire message exists (or should exist) for "send me your data directory
 * as a tarball." A caller runs this on a machine that can already see the
 * server's data directory, exactly the assumption `user add/remove/list`
 * already makes. See DECISIONS.md.
 */
final class BackupCommand
{
    /**
     * @param array<string, list<string>> $options
     * @param resource                    $output
     * @param resource                    $errorOutput
     */
    public function run(array $options, mixed $output, mixed $errorOutput): int
    {
        $dataDirectory = $options['data'][0] ?? null;
        $archivePath = $options['output'][0] ?? null;

        if ($dataDirectory === null || $archivePath === null) {
            fwrite($errorOutput, "backup requires --data <dir> and --output <archive.tar.gz>.\n");

            return 1;
        }

        try {
            (new BackupManager())->backup($dataDirectory, $archivePath);
        } catch (Throwable $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        fwrite($output, sprintf("Backed up \"%s\" to \"%s\".\n", $dataDirectory, $archivePath));

        return 0;
    }
}

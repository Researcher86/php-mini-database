<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

/** `bin/minidb restore` — see `BackupCommand`'s own docblock; same deferral, same reason. */
final class RestoreCommand
{
    /** @param resource $errorOutput */
    public function run(mixed $errorOutput): int
    {
        fwrite($errorOutput, "restore is not implemented yet - see PLAN.md Milestone 19 (Backup, Dump, Restore).\n");

        return 1;
    }
}

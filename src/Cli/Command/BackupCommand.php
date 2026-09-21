<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

/**
 * `bin/minidb backup` — named on Milestone 17's own checklist, but its
 * actual mechanism (`Backup\BackupManager`, tar.gz backups) is Milestone
 * 19's job, not this one's. Recognized here as a real subcommand rather
 * than falling into "unknown command", but honest that there is nothing
 * behind it yet, rather than improvising a backup format this project's
 * own plan gives a different milestone to design. See DECISIONS.md.
 */
final class BackupCommand
{
    /** @param resource $errorOutput */
    public function run(mixed $errorOutput): int
    {
        fwrite($errorOutput, "backup is not implemented yet - see PLAN.md Milestone 19 (Backup, Dump, Restore).\n");

        return 1;
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Backup;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Sql\StatementSplitter;

/**
 * `Dumper`'s counterpart — replays a SQL dump's statements through one
 * `Execution\Executor`, in order. `Sql\StatementSplitter` is what makes a
 * whole dump file into individual statements first, the same class
 * `Cli\Command\ImportCommand` and `Cli\Repl` already use for the same
 * reason: `Executor::run()` parses exactly one statement at a time.
 *
 * Stops at the first failing statement and lets the exception propagate —
 * a dump either restores cleanly or the caller finds out immediately
 * which statement broke and why, the same "stop, do not guess how to keep
 * going" default `Cli\Command\ImportCommand` already chose.
 */
final class Restorer
{
    public function __construct(
        private readonly Executor $executor,
    ) {
    }

    /** @return int how many statements ran */
    public function restore(string $sql): int
    {
        $statements = StatementSplitter::split($sql);

        foreach ($statements as $statement) {
            $this->executor->run($statement);
        }

        return count($statements);
    }
}

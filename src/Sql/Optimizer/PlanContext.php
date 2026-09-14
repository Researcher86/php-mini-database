<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer;

use PhpMiniDatabase\Schema\Database;

/**
 * What a `Rule` needs beyond the plan tree itself. Today that is only the
 * `Database` — `Rule\JoinReordering` reads a table's page count as a cheap
 * size estimate through it, and `Rule\IndexSelection` reads a `Scan`'s own
 * `Table` object, which the plan already carries.
 */
final readonly class PlanContext
{
    public function __construct(
        public Database $database,
    ) {
    }
}

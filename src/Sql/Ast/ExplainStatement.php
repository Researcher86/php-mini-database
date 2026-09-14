<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * `EXPLAIN <select>` — describes the plan `Execution\Executor` would run
 * for the wrapped `SELECT` instead of running it. Any statement other than
 * `SELECT` is refused by the parser: there is no plan to show for a
 * statement whose execution never goes through `Sql\Planner\Planner`.
 */
final readonly class ExplainStatement implements Statement
{
    public function __construct(
        public SelectStatement $statement,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner;

/**
 * `Planner::plan()`'s result: the tree, plus the output column labels a
 * `QueryResult` needs and that only the `Plan\Project`/`Plan\Aggregate`
 * node buried somewhere inside the tree actually knows. Carrying them
 * alongside the plan is simpler than having a caller walk the tree back
 * down to find whichever node has them.
 */
final readonly class PlannedQuery
{
    /** @param list<string> $labels */
    public function __construct(
        public LogicalPlan $plan,
        public array $labels,
    ) {
    }
}

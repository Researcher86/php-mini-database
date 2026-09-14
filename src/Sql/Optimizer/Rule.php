<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer;

use PhpMiniDatabase\Sql\Planner\LogicalPlan;

/**
 * One rewrite `Optimizer` applies to a whole plan tree. A rule matches and
 * rebuilds the node types it cares about directly — the same way
 * `Execution\Expression\Evaluator` matches `Expression` subtypes directly
 * rather than through a generic visitor — recursing into every node's
 * children so a shape buried several levels down (a `Filter` sitting under
 * a `Join`'s `Aggregate`, say) is still found.
 */
interface Rule
{
    public function apply(LogicalPlan $plan, PlanContext $context): LogicalPlan;
}

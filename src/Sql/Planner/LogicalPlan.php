<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner;

/**
 * One node of the tree `Planner::plan()` builds from a `SelectStatement`
 * and `Optimizer::optimize()` rewrites: what a query reads and does, before
 * `Execution\Executor` turns it into running `Operator`s.
 *
 * There is no separate physical-plan type alongside this one, unlike the
 * `LogicalPlan`/`PhysicalPlan` split PLAN.md's own file layout sketches.
 * `Plan\Scan` and `Plan\Join` carry an access-method field
 * (`Scan::$index`, `Join::$hash`) that starts `null` and is filled in by an
 * `Optimizer` rule once one applies — the same node changes from "not yet
 * decided" to "decided" in place, rather than a whole second tree being
 * built to hold that decision. See DECISIONS.md.
 *
 * `describe()`/`children()` exist for `EXPLAIN` alone: a rule that rewrites
 * a plan matches and rebuilds each node type it cares about directly (see
 * any `Sql\Optimizer\Rule\*`), the same way `Execution\Expression\Evaluator`
 * matches `Expression` subtypes directly rather than through a generic
 * visitor — but printing a plan tree has no reason to know one node type
 * from another, so it is the one consumer that benefits from a generic walk.
 */
interface LogicalPlan
{
    /** One line describing this node alone, without its children. */
    public function describe(): string;

    /** @return list<LogicalPlan> */
    public function children(): array;
}

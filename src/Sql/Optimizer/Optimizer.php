<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer;

use PhpMiniDatabase\Sql\Optimizer\Rule\ConstantFolding;
use PhpMiniDatabase\Sql\Optimizer\Rule\IndexSelection;
use PhpMiniDatabase\Sql\Optimizer\Rule\JoinReordering;
use PhpMiniDatabase\Sql\Optimizer\Rule\PredicatePushdown;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;

/**
 * Rewrites a `LogicalPlan` into the one `Execution\Executor` actually runs,
 * by applying a fixed sequence of `Rule`s — there is no cost-based search
 * over alternative rewrites, each rule always fires wherever its shape
 * matches. The order is deliberate: `ConstantFolding` first, so every later
 * rule sees already-simplified expressions; `PredicatePushdown` before
 * `IndexSelection`, so a conjunct pushed onto a join side is still there to
 * match against that side's indexes; `JoinReordering` last, since neither
 * of the other two changes a join's row-count estimate.
 */
final readonly class Optimizer
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new ConstantFolding(),
            new PredicatePushdown(),
            new IndexSelection(),
            new JoinReordering(),
        ];
    }

    public function optimize(LogicalPlan $plan, PlanContext $context): LogicalPlan
    {
        foreach ($this->rules as $rule) {
            $plan = $rule->apply($plan, $context);
        }

        return $plan;
    }
}

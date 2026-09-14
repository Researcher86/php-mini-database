<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer\Rule;

use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\From\JoinType;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\HashJoinKeys;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Plan\Sort;

/**
 * Recognizes an `INNER JOIN` whose `ON` is a plain equality between one
 * qualified column of each side — the shape `Execution\Operator\HashJoin`
 * can run — and, when it estimates one side as smaller than the other,
 * swaps which physical side is `left`/`right` so the smaller one becomes
 * `HashJoin`'s hash-built side (it always builds from `$right`; see that
 * class's docblock). Estimating "smaller" costs one `HeapFile::pageCount()`
 * call per side — an existing, already-cached count, not a scan — and is
 * skipped (leaving the `FROM` clause's own order) whenever either side is
 * not directly a table (a nested `Join`, say): general N-way join-order
 * search is out of scope for the reason `IndexScan`/`HashJoin` selection
 * already gave through Phase 8 — a small, fixed rule that is never *wrong*,
 * only sometimes not the fastest available plan. See DECISIONS.md.
 *
 * An outer join's operand order is never touched, for a reason that is not
 * about scope: swapping a `LEFT JOIN`'s sides would turn it into a
 * `RIGHT JOIN` and change which rows the query returns.
 */
final readonly class JoinReordering implements Rule
{
    public function apply(LogicalPlan $plan, PlanContext $context): LogicalPlan
    {
        return match (true) {
            $plan instanceof Filter => new Filter($this->apply($plan->source, $context), $plan->predicate),
            $plan instanceof Join => $this->applyJoin($plan, $context),
            $plan instanceof Aggregate => new Aggregate($this->apply($plan->source, $context), $plan->groupBy, $plan->items, $plan->labels, $plan->having),
            $plan instanceof Sort => new Sort($this->apply($plan->source, $context), $plan->orderBy),
            $plan instanceof Project => new Project($this->apply($plan->source, $context), $plan->items, $plan->labels),
            $plan instanceof Distinct => new Distinct($this->apply($plan->source, $context)),
            $plan instanceof Limit => new Limit($this->apply($plan->source, $context), $plan->limit, $plan->offset),
            default => $plan,
        };
    }

    private function applyJoin(Join $plan, PlanContext $context): Join
    {
        $join = $plan->withSides($this->apply($plan->left, $context), $this->apply($plan->right, $context));

        if ($join->type !== JoinType::INNER) {
            return $join;
        }

        $keys = $this->equiJoinKeys($join);

        if ($keys === null) {
            return $join;
        }

        [$leftKey, $rightKey] = $keys;

        if ($this->shouldSwapForSize($join, $context)) {
            $join = $join->withSides($join->right, $join->left);
            [$leftKey, $rightKey] = [$rightKey, $leftKey];
        }

        return $join->withHash(new HashJoinKeys($leftKey, $rightKey));
    }

    private function shouldSwapForSize(Join $join, PlanContext $context): bool
    {
        $leftSize = $this->estimateRows($join->left, $context);
        $rightSize = $this->estimateRows($join->right, $context);

        return $leftSize !== null && $rightSize !== null && $leftSize < $rightSize;
    }

    private function estimateRows(LogicalPlan $plan, PlanContext $context): ?int
    {
        if ($plan instanceof Scan) {
            return $context->database->heapFile($plan->table->name)->pageCount();
        }

        if ($plan instanceof Filter) {
            return $this->estimateRows($plan->source, $context);
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null qualified left key, then qualified right key */
    private function equiJoinKeys(Join $join): ?array
    {
        if (!$join->on instanceof BinaryOp
            || $join->on->operator !== BinaryOperator::EQUAL
            || !$join->on->left instanceof ColumnRef
            || !$join->on->right instanceof ColumnRef
            || $join->on->left->qualifier === null
            || $join->on->right->qualifier === null
        ) {
            return null;
        }

        $leftRefs = $this->tableRefs($join->left);
        $rightRefs = $this->tableRefs($join->right);
        $a = $join->on->left;
        $b = $join->on->right;

        if (in_array($a->qualifier, $leftRefs, true) && in_array($b->qualifier, $rightRefs, true)) {
            return ["{$a->qualifier}.{$a->column}", "{$b->qualifier}.{$b->column}"];
        }

        if (in_array($b->qualifier, $leftRefs, true) && in_array($a->qualifier, $rightRefs, true)) {
            return ["{$b->qualifier}.{$b->column}", "{$a->qualifier}.{$a->column}"];
        }

        return null;
    }

    /** @return list<string> */
    private function tableRefs(LogicalPlan $plan): array
    {
        if ($plan instanceof Scan) {
            return [$plan->reference()];
        }

        if ($plan instanceof Join) {
            return [...$this->tableRefs($plan->left), ...$this->tableRefs($plan->right)];
        }

        if ($plan instanceof Filter) {
            return $this->tableRefs($plan->source);
        }

        return [];
    }
}

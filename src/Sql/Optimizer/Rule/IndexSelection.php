<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer\Rule;

use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\IndexAccess;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Plan\Sort;

/**
 * Attaches an `IndexAccess` to a `Scan` sitting directly under a `Filter`,
 * when that `Filter`'s predicate (or the first conjunct of a top-level
 * `AND` chain) is a plain `column <op> constant` comparison against a
 * column with a single-column index. This is the exact rule
 * `Execution\Executor::selectSource()` applied ad hoc through Phase 8,
 * extracted unchanged — including running unconditionally *after*
 * `PredicatePushdown`, so a conjunct that rule moved onto one side of a
 * `Join` gets exactly the same chance at an index that a plain
 * single-table `WHERE` always had, which nothing before Phase 9 gave it.
 *
 * `Filter` is never removed once an index is chosen: an `IndexScan` only
 * ever narrows what it reads to rows that are *plausibly* covered by the
 * comparison, not the whole condition (an `AND` chain past the first
 * conjunct, say) — the `Filter` above still re-validates every row.
 */
final readonly class IndexSelection implements Rule
{
    public function apply(LogicalPlan $plan, PlanContext $context): LogicalPlan
    {
        return match (true) {
            $plan instanceof Filter => $this->applyFilter($plan, $context),
            $plan instanceof Join => $plan->withSides($this->apply($plan->left, $context), $this->apply($plan->right, $context)),
            $plan instanceof Aggregate => new Aggregate($this->apply($plan->source, $context), $plan->groupBy, $plan->items, $plan->labels, $plan->having),
            $plan instanceof Sort => new Sort($this->apply($plan->source, $context), $plan->orderBy),
            $plan instanceof Project => new Project($this->apply($plan->source, $context), $plan->items, $plan->labels),
            $plan instanceof Distinct => new Distinct($this->apply($plan->source, $context)),
            $plan instanceof Limit => new Limit($this->apply($plan->source, $context), $plan->limit, $plan->offset),
            default => $plan,
        };
    }

    private function applyFilter(Filter $plan, PlanContext $context): LogicalPlan
    {
        $source = $this->apply($plan->source, $context);

        if (!$source instanceof Scan || $source->index !== null) {
            return new Filter($source, $plan->predicate);
        }

        $predicate = $this->indexablePredicate($plan->predicate);
        $indexName = $predicate === null ? null : $this->singleColumnIndexNameFor($source->table, $predicate['column']);

        if ($predicate === null || $indexName === null) {
            return new Filter($source, $plan->predicate);
        }

        $index = new IndexAccess($indexName, $predicate['column'], $predicate['op'], $predicate['value']);

        return new Filter($source->withIndex($index), $plan->predicate);
    }

    /** @return array{column: string, op: 'eq'|'lt'|'lte'|'gt'|'gte', value: Expression}|null */
    private function indexablePredicate(Expression $where): ?array
    {
        if (!$where instanceof BinaryOp) {
            return null;
        }

        if ($where->operator === BinaryOperator::AND) {
            return $this->indexablePredicate($where->left) ?? $this->indexablePredicate($where->right);
        }

        $op = match ($where->operator) {
            BinaryOperator::EQUAL => 'eq',
            BinaryOperator::LESS_THAN => 'lt',
            BinaryOperator::LESS_THAN_OR_EQUAL => 'lte',
            BinaryOperator::GREATER_THAN => 'gt',
            BinaryOperator::GREATER_THAN_OR_EQUAL => 'gte',
            default => null,
        };

        if ($op === null || !$where->left instanceof ColumnRef || !$this->isConstant($where->right)) {
            return null;
        }

        return ['column' => $where->left->column, 'op' => $op, 'value' => $where->right];
    }

    private function isConstant(Expression $expression): bool
    {
        return $expression instanceof Literal || $expression instanceof Placeholder;
    }

    private function singleColumnIndexNameFor(Table $table, string $column): ?string
    {
        foreach ($table->indexes() as $definition) {
            if ($definition->columns() === [$column]) {
                return $definition->name;
            }
        }

        return null;
    }
}

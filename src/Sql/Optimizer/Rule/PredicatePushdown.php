<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer\Rule;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Between;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\InList;
use PhpMiniDatabase\Sql\Ast\Expression\IsNull;
use PhpMiniDatabase\Sql\Ast\Expression\Like;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\From\JoinType;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Plan\Sort;

/**
 * Moves each top-level `AND` conjunct of a `WHERE` sitting above a `Join`
 * down onto whichever side alone can answer it, so that side's `Scan`
 * (and, once `IndexSelection` runs, its index) sees a narrower predicate
 * before the join ever runs — a `HashJoin`/`NestedLoopJoin` then does less
 * work on that side, and `IndexSelection` gets a chance it did not have
 * before this rule existed: `Execution\Executor` never used an `IndexScan`
 * for a join side prior to Phase 9, since nothing before this ran a per-
 * side predicate through it.
 *
 * Two restrictions are not narrowing choices but correctness requirements.
 * First, a conjunct only pushes past a side that is never the outer join's
 * nullable one: pushing a `WHERE` predicate onto a `LEFT JOIN`'s right side
 * (or a `RIGHT JOIN`'s left) would filter rows out *before* the join has a
 * chance to null-pad them for a left row with no match, changing which
 * rows the query returns — a well-known pitfall of pushdown past outer
 * joins, not a simplification specific to this project. Second, a conjunct
 * that references an unqualified column, or spans both sides, is left
 * exactly where it was (the residual `Filter` above the join): resolving
 * an unqualified reference to a specific side needs the runtime
 * ambiguity-checking `Execution\Expression\QualifiedRowContext` already
 * does, which this rule does not reproduce statically — see DECISIONS.md.
 */
final readonly class PredicatePushdown implements Rule
{
    public function apply(LogicalPlan $plan, PlanContext $context): LogicalPlan
    {
        return match (true) {
            $plan instanceof Filter && $plan->source instanceof Join => $this->pushdownFilter($plan, $context),
            $plan instanceof Filter => new Filter($this->apply($plan->source, $context), $plan->predicate),
            $plan instanceof Join => $plan->withSides($this->apply($plan->left, $context), $this->apply($plan->right, $context)),
            $plan instanceof Aggregate => new Aggregate(
                $this->apply($plan->source, $context),
                $plan->groupBy,
                $plan->items,
                $plan->labels,
                $plan->having,
            ),
            $plan instanceof Sort => new Sort($this->apply($plan->source, $context), $plan->orderBy),
            $plan instanceof Project => new Project($this->apply($plan->source, $context), $plan->items, $plan->labels),
            $plan instanceof Distinct => new Distinct($this->apply($plan->source, $context)),
            $plan instanceof Limit => new Limit($this->apply($plan->source, $context), $plan->limit, $plan->offset),
            default => $plan,
        };
    }

    private function pushdownFilter(Filter $plan, PlanContext $context): LogicalPlan
    {
        /** @var Join $join */
        $join = $this->apply($plan->source, $context);
        $residual = [];

        foreach ($this->splitConjuncts($plan->predicate) as $conjunct) {
            $refs = $this->referencesOf($conjunct);
            $pushed = $refs !== null ? $this->tryPush($join, $conjunct, $refs) : null;

            if ($pushed !== null) {
                $join = $pushed;
            } else {
                $residual[] = $conjunct;
            }
        }

        return $residual === [] ? $join : new Filter($join, $this->combineWithAnd($residual));
    }

    /** @return list<Expression> */
    private function splitConjuncts(Expression $expression): array
    {
        if ($expression instanceof BinaryOp && $expression->operator === BinaryOperator::AND) {
            return [...$this->splitConjuncts($expression->left), ...$this->splitConjuncts($expression->right)];
        }

        return [$expression];
    }

    /** @param non-empty-list<Expression> $conjuncts */
    private function combineWithAnd(array $conjuncts): Expression
    {
        $combined = array_shift($conjuncts);

        foreach ($conjuncts as $conjunct) {
            $combined = new BinaryOp($combined, BinaryOperator::AND, $conjunct);
        }

        return $combined;
    }

    /**
     * @param list<string> $refs
     */
    private function tryPush(Join $join, Expression $conjunct, array $refs): ?Join
    {
        if (!$this->isNullableSide($join, 'left') && $this->isSubset($refs, $this->referencesIn($join->left))) {
            return $join->withSides($this->descend($join->left, $conjunct, $refs), $join->right);
        }

        if (!$this->isNullableSide($join, 'right') && $this->isSubset($refs, $this->referencesIn($join->right))) {
            return $join->withSides($join->left, $this->descend($join->right, $conjunct, $refs));
        }

        return null;
    }

    /** @param list<string> $refs */
    private function descend(LogicalPlan $plan, Expression $conjunct, array $refs): LogicalPlan
    {
        if ($plan instanceof Join) {
            $pushed = $this->tryPush($plan, $conjunct, $refs);

            if ($pushed !== null) {
                return $pushed;
            }
        }

        return new Filter($plan, $conjunct);
    }

    private function isNullableSide(Join $join, string $side): bool
    {
        return match ($join->type) {
            JoinType::INNER => false,
            JoinType::LEFT => $side === 'right',
            JoinType::RIGHT => $side === 'left',
        };
    }

    /** @return list<string> */
    private function referencesIn(LogicalPlan $plan): array
    {
        if ($plan instanceof Scan) {
            return [$plan->reference()];
        }

        if ($plan instanceof Join) {
            return [...$this->referencesIn($plan->left), ...$this->referencesIn($plan->right)];
        }

        if ($plan instanceof Filter) {
            return $this->referencesIn($plan->source);
        }

        return [];
    }

    /**
     * @param list<string> $refs
     * @param list<string> $available
     */
    private function isSubset(array $refs, array $available): bool
    {
        return array_all($refs, static fn (string $ref): bool => in_array($ref, $available, true));
    }

    /**
     * The qualifiers a conjunct references, or `null` if it touches an
     * unqualified column (cannot be resolved to a side statically) or
     * anything else this rule will not reason about (a subquery, `*`).
     *
     * @return list<string>|null
     */
    private function referencesOf(Expression $expression): ?array
    {
        $refs = [];

        return $this->collectReferences($expression, $refs) ? array_values(array_unique($refs)) : null;
    }

    /** @param list<string> $refs */
    private function collectReferences(Expression $expression, array &$refs): bool
    {
        if ($expression instanceof ColumnRef) {
            if ($expression->qualifier === null) {
                return false;
            }

            $refs[] = $expression->qualifier;

            return true;
        }

        if ($expression instanceof Literal || $expression instanceof Placeholder) {
            return true;
        }

        if ($expression instanceof BinaryOp) {
            return $this->collectReferences($expression->left, $refs) && $this->collectReferences($expression->right, $refs);
        }

        if ($expression instanceof UnaryOp) {
            return $this->collectReferences($expression->operand, $refs);
        }

        if ($expression instanceof Between) {
            return $this->collectReferences($expression->subject, $refs)
                && $this->collectReferences($expression->low, $refs)
                && $this->collectReferences($expression->high, $refs);
        }

        if ($expression instanceof Like) {
            return $this->collectReferences($expression->subject, $refs) && $this->collectReferences($expression->pattern, $refs);
        }

        if ($expression instanceof IsNull) {
            return $this->collectReferences($expression->subject, $refs);
        }

        if ($expression instanceof InList) {
            foreach ([$expression->subject, ...$expression->values] as $part) {
                if (!$this->collectReferences($part, $refs)) {
                    return false;
                }
            }

            return true;
        }

        if ($expression instanceof FunctionCall) {
            foreach ($expression->arguments as $argument) {
                if ($argument instanceof Star) {
                    continue;
                }

                if (!$this->collectReferences($argument, $refs)) {
                    return false;
                }
            }

            return true;
        }

        // Star outside a function call, or a subquery: not safe to reason
        // about statically, so this conjunct stays exactly where it was.
        return false;
    }
}

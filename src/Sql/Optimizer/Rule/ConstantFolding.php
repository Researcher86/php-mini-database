<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Optimizer\Rule;

use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Between;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\InList;
use PhpMiniDatabase\Sql\Ast\Expression\IsNull;
use PhpMiniDatabase\Sql\Ast\Expression\Like;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\OrderByItem;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Optimizer\Rule;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Sort;

/**
 * Collapses an arithmetic or logical subexpression whose operands are both
 * already literal — `1 + 2`, `age > 10 AND TRUE` — into the one `Literal`
 * it evaluates to, so a per-row `Evaluator::evaluate()` call never redoes
 * that arithmetic on every row.
 *
 * A `Placeholder` is never folded, deliberately: it stands for a value this
 * rule cannot see (bound only once the statement actually runs), and
 * folding it would mean baking one parameter binding's answer into a plan
 * meant to still be correct for whatever value the *next* binding gives it.
 * Nor is a function call ever collapsed to its result, even one applied
 * only to literals (`UPPER('a')`) — the safe cases would need to be told
 * apart from a non-deterministic one (`CURRENT_TIMESTAMP`, `RANDOM()`) by
 * name, and this rule does not carry that list; only a function's *argument*
 * expressions are folded, not the call itself. `BETWEEN`/`LIKE`/`IN`
 * likewise keep their own shape even once every part of them is literal —
 * only `BinaryOp` and `UnaryOp` ever collapse.
 */
final readonly class ConstantFolding implements Rule
{
    public function __construct(
        private Evaluator $evaluator = new Evaluator(),
    ) {
    }

    public function apply(LogicalPlan $plan, PlanContext $context): LogicalPlan
    {
        return match (true) {
            $plan instanceof Filter => new Filter($this->apply($plan->source, $context), $this->fold($plan->predicate)),
            $plan instanceof Join => new Join(
                $this->apply($plan->left, $context),
                $plan->type,
                $this->apply($plan->right, $context),
                $this->fold($plan->on),
                $plan->hash,
            ),
            $plan instanceof Aggregate => new Aggregate(
                $this->apply($plan->source, $context),
                array_map($this->fold(...), $plan->groupBy),
                $this->foldItems($plan->items),
                $plan->labels,
                $plan->having !== null ? $this->fold($plan->having) : null,
            ),
            $plan instanceof Sort => new Sort($this->apply($plan->source, $context), $this->foldOrderBy($plan->orderBy)),
            $plan instanceof Project => new Project($this->apply($plan->source, $context), $this->foldItems($plan->items), $plan->labels),
            $plan instanceof Distinct => new Distinct($this->apply($plan->source, $context)),
            $plan instanceof Limit => new Limit($this->apply($plan->source, $context), $plan->limit, $plan->offset),
            default => $plan,
        };
    }

    private function fold(Expression $expression): Expression
    {
        if ($expression instanceof BinaryOp) {
            $left = $this->fold($expression->left);
            $right = $this->fold($expression->right);

            if ($left instanceof Literal && $right instanceof Literal) {
                return new Literal($this->evaluator->evaluate(new BinaryOp($left, $expression->operator, $right), new RowContext()));
            }

            return new BinaryOp($left, $expression->operator, $right);
        }

        if ($expression instanceof UnaryOp) {
            $operand = $this->fold($expression->operand);

            if ($operand instanceof Literal) {
                return new Literal($this->evaluator->evaluate(new UnaryOp($expression->operator, $operand), new RowContext()));
            }

            return new UnaryOp($expression->operator, $operand);
        }

        if ($expression instanceof Between) {
            return new Between($this->fold($expression->subject), $this->fold($expression->low), $this->fold($expression->high), $expression->negated);
        }

        if ($expression instanceof Like) {
            return new Like($this->fold($expression->subject), $this->fold($expression->pattern), $expression->negated);
        }

        if ($expression instanceof InList) {
            return new InList($this->fold($expression->subject), array_map($this->fold(...), $expression->values), $expression->negated);
        }

        if ($expression instanceof IsNull) {
            return new IsNull($this->fold($expression->subject), $expression->negated);
        }

        if ($expression instanceof FunctionCall) {
            return new FunctionCall($expression->name, array_map($this->fold(...), $expression->arguments), $expression->distinct);
        }

        return $expression;
    }

    /**
     * @param list<SelectItem> $items
     *
     * @return list<SelectItem>
     */
    private function foldItems(array $items): array
    {
        return array_map(
            fn (SelectItem $item): SelectItem => new SelectItem($this->fold($item->expression), $item->alias),
            $items,
        );
    }

    /**
     * @param list<OrderByItem> $orderBy
     *
     * @return list<OrderByItem>
     */
    private function foldOrderBy(array $orderBy): array
    {
        return array_map(
            fn (OrderByItem $item): OrderByItem => new OrderByItem($this->fold($item->expression), $item->direction),
            $orderBy,
        );
    }
}

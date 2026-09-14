<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\From\JoinType;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\PlanExpressionPrinter;

/**
 * Two plans joined on `$on`. `$hash`, filled in by
 * `Sql\Optimizer\Rule\JoinReordering`, is both the decision that `$on` is a
 * plain equi-join `HashJoin` can run and — since that rule also chooses
 * which side becomes the hash-built one — the reason `$left`/`$right` may
 * not be in the order the `FROM` clause named them. `NestedLoopJoin` is
 * every other case: `$hash === null`.
 */
final readonly class Join implements LogicalPlan
{
    public function __construct(
        public LogicalPlan $left,
        public JoinType $type,
        public LogicalPlan $right,
        public Expression $on,
        public ?HashJoinKeys $hash = null,
    ) {
    }

    public function withSides(LogicalPlan $left, LogicalPlan $right): self
    {
        return new self($left, $this->type, $right, $this->on, $this->hash);
    }

    public function withHash(?HashJoinKeys $hash): self
    {
        return new self($this->left, $this->type, $this->right, $this->on, $hash);
    }

    public function describe(): string
    {
        return sprintf(
            '%s%s (%s)',
            $this->hash !== null ? 'HashJoin ' : 'NestedLoopJoin ',
            $this->type->name,
            (new PlanExpressionPrinter())->print($this->on),
        );
    }

    public function children(): array
    {
        return [$this->left, $this->right];
    }
}

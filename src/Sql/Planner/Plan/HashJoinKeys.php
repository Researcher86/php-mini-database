<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

/**
 * Marks a `Join` as `$on` being exactly `leftKey = rightKey` between one
 * qualified column of each side — the one shape `Execution\Operator\HashJoin`
 * can run in one pass instead of `NestedLoopJoin`'s pairwise comparison.
 * Set by `Sql\Optimizer\Rule\JoinReordering` once it recognizes the shape;
 * `null` (the default) means `NestedLoopJoin` is what compiles.
 */
final readonly class HashJoinKeys
{
    public function __construct(
        public string $leftKey,
        public string $rightKey,
    ) {
    }
}

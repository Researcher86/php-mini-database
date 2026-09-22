<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Support;

use PhpMiniDatabase\Sql\Planner\LogicalPlan;

/**
 * Walks past a chain of ancestor node types in a `LogicalPlan` tree,
 * landing on whatever comes after the last one named.
 *
 * A `LogicalPlan` node's child is typed as the interface, not the concrete
 * class it happens to hold (`Plan\Filter::$source: LogicalPlan`, not
 * `Plan\Scan`) — correctly, since any node type can sit there. PHPStan
 * therefore refuses `$plan->source->source` outright: nothing has told it
 * what `$plan->source` narrows to. This walks through `children()`
 * (generic, needs no narrowing) and only asks a caller to
 * `self::assertInstanceOf()` once, on the node actually being tested,
 * rather than at every intermediate step of a long chain.
 */
trait PlanAssertions
{
    /** @param class-string<LogicalPlan> ...$ancestors */
    private static function descend(LogicalPlan $plan, string ...$ancestors): LogicalPlan
    {
        foreach ($ancestors as $ancestor) {
            self::assertInstanceOf($ancestor, $plan);

            $children = $plan->children();
            self::assertNotSame([], $children, sprintf('%s has no child to descend into.', $ancestor));

            $plan = $children[0];
        }

        return $plan;
    }
}

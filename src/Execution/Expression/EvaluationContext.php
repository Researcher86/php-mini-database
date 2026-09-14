<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Expression;

use PhpMiniDatabase\Exception\ExecutionException;

/**
 * What `Evaluator` asks the caller for while walking an expression: a
 * column's current value, and a bound parameter's value for a `?`
 * placeholder. Kept as an interface rather than `Evaluator` taking a `Row`
 * directly, because not every expression has a row behind it at all — a
 * column default, or a `SELECT` with no `FROM` — and those still need to
 * answer `parameter()` for a bound value even though `column()` can only
 * refuse.
 */
interface EvaluationContext
{
    /** @throws ExecutionException when there is no such column to read */
    public function column(?string $qualifier, string $name): mixed;

    /** @throws ExecutionException when no parameter was bound at that index */
    public function parameter(int $index): mixed;
}

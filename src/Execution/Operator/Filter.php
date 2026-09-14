<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Closure;
use Generator;
use PhpMiniDatabase\Execution\Expression\EvaluationContext;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * Yields only the rows of its source for which `$predicate` evaluates to a
 * definite `true` — `Evaluator::isTrue()` is what decides that, so a `NULL`
 * (unknown) result excludes a row exactly like `false` does, per SQL's
 * three-valued logic.
 *
 * `$contextFor` builds the `EvaluationContext` a given row is checked
 * against — a plain `RowContext` for a single table, a `QualifiedRowContext`
 * once a `JOIN` is involved (Phase 7) — so this class stays ignorant of
 * which kind of query produced the row it was handed.
 */
final readonly class Filter implements Operator
{
    /** @param Closure(Row): EvaluationContext $contextFor */
    public function __construct(
        private Operator $source,
        private Expression $predicate,
        private Evaluator $evaluator,
        private Closure $contextFor,
    ) {
    }

    public function getIterator(): Generator
    {
        foreach ($this->source as $id => $row) {
            if ($this->evaluator->isTrue($this->evaluator->evaluate($this->predicate, ($this->contextFor)($row)))) {
                yield $id => $row;
            }
        }
    }
}

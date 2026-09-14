<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * Yields only the rows of its source for which `$predicate` evaluates to a
 * definite `true` — `Evaluator::isTrue()` is what decides that, so a `NULL`
 * (unknown) result excludes a row exactly like `false` does, per SQL's
 * three-valued logic.
 */
final readonly class Filter implements Operator
{
    /** @param list<mixed> $parameters */
    public function __construct(
        private Operator $source,
        private Expression $predicate,
        private Evaluator $evaluator,
        private ?string $tableName,
        private ?string $tableAlias = null,
        private array $parameters = [],
    ) {
    }

    public function getIterator(): Generator
    {
        foreach ($this->source as $id => $row) {
            $context = new RowContext($row, $this->tableName, $this->tableAlias, $this->parameters);

            if ($this->evaluator->isTrue($this->evaluator->evaluate($this->predicate, $context))) {
                yield $id => $row;
            }
        }
    }
}

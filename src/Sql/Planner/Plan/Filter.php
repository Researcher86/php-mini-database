<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\ExpressionPrinter;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;

/** Keeps only the rows of `$source` for which `$predicate` is true. */
final readonly class Filter implements LogicalPlan
{
    public function __construct(
        public LogicalPlan $source,
        public Expression $predicate,
    ) {
    }

    public function describe(): string
    {
        return sprintf('Filter (%s)', ExpressionPrinter::forExplain()->print($this->predicate));
    }

    public function children(): array
    {
        return [$this->source];
    }
}

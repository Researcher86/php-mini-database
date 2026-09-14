<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\PlanExpressionPrinter;

/** `GROUP BY`/`HAVING` and the select list's aggregates, together. */
final readonly class Aggregate implements LogicalPlan
{
    /**
     * @param list<Expression> $groupBy
     * @param list<SelectItem> $items
     * @param list<string>     $labels
     */
    public function __construct(
        public LogicalPlan $source,
        public array $groupBy,
        public array $items,
        public array $labels,
        public ?Expression $having = null,
    ) {
    }

    public function describe(): string
    {
        $printer = new PlanExpressionPrinter();
        $groupBy = implode(', ', array_map($printer->print(...), $this->groupBy));

        return sprintf('Aggregate (%s)%s', $groupBy, $this->having !== null ? sprintf(' HAVING (%s)', $printer->print($this->having)) : '');
    }

    public function children(): array
    {
        return [$this->source];
    }
}

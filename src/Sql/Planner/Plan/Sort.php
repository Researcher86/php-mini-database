<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Ast\OrderByItem;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\PlanExpressionPrinter;

final readonly class Sort implements LogicalPlan
{
    /** @param list<OrderByItem> $orderBy */
    public function __construct(
        public LogicalPlan $source,
        public array $orderBy,
    ) {
    }

    public function describe(): string
    {
        $printer = new PlanExpressionPrinter();

        return sprintf('Sort (%s)', implode(', ', array_map(
            static fn (OrderByItem $item): string => $printer->print($item->expression) . ' ' . $item->direction->name,
            $this->orderBy,
        )));
    }

    public function children(): array
    {
        return [$this->source];
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;

/** Evaluates the select list, one output row per input row. */
final readonly class Project implements LogicalPlan
{
    /**
     * @param list<SelectItem> $items
     * @param list<string>     $labels
     */
    public function __construct(
        public LogicalPlan $source,
        public array $items,
        public array $labels,
    ) {
    }

    public function describe(): string
    {
        return sprintf('Project (%s)', implode(', ', $this->labels));
    }

    public function children(): array
    {
        return [$this->source];
    }
}

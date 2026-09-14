<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Planner\LogicalPlan;

final readonly class Distinct implements LogicalPlan
{
    public function __construct(
        public LogicalPlan $source,
    ) {
    }

    public function describe(): string
    {
        return 'Distinct';
    }

    public function children(): array
    {
        return [$this->source];
    }
}

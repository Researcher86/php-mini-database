<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Planner\LogicalPlan;

final readonly class Limit implements LogicalPlan
{
    public function __construct(
        public LogicalPlan $source,
        public ?int $limit,
        public int $offset,
    ) {
    }

    public function describe(): string
    {
        return sprintf('Limit %s OFFSET %d', $this->limit === null ? 'ALL' : (string) $this->limit, $this->offset);
    }

    public function children(): array
    {
        return [$this->source];
    }
}

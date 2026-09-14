<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\PlanExpressionPrinter;

/**
 * Reads every row of one table. `$alias` is exactly `TableReference::$alias`
 * — `null` when the query never gave the table one — and `reference()` is
 * the name (its own, or that alias) a qualified column reference, or a
 * join's `Qualify`, would use for it.
 *
 * `$index` is `null` until `Sql\Optimizer\Rule\IndexSelection` fills it in —
 * a plain sequential read is this node's default, exactly as `SeqScan` is
 * `Execution\Executor`'s default before Phase 6's index rule was extracted
 * into that same class.
 */
final readonly class Scan implements LogicalPlan
{
    public function __construct(
        public Table $table,
        public ?string $alias = null,
        public ?IndexAccess $index = null,
    ) {
    }

    public function reference(): string
    {
        return $this->alias ?? $this->table->name;
    }

    public function withIndex(IndexAccess $index): self
    {
        return new self($this->table, $this->alias, $index);
    }

    public function describe(): string
    {
        if ($this->index === null) {
            return sprintf('SeqScan %s', $this->reference());
        }

        return sprintf(
            'IndexScan %s USING %s (%s %s %s)',
            $this->reference(),
            $this->index->indexName,
            $this->index->column,
            $this->operatorSymbol($this->index->operator),
            (new PlanExpressionPrinter())->print($this->index->value),
        );
    }

    public function children(): array
    {
        return [];
    }

    /** @param 'eq'|'lt'|'lte'|'gt'|'gte' $operator */
    private function operatorSymbol(string $operator): string
    {
        return match ($operator) {
            'eq' => '=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
        };
    }
}

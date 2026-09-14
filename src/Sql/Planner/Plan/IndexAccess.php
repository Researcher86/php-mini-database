<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner\Plan;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * How a `Scan` reaches its rows through an index instead of reading every
 * one: `$operator` and `$value` are exactly the shape
 * `Sql\Optimizer\Rule\IndexSelection` looks for — `column <op> constant` —
 * carried here once found, for `Execution\Executor` to build an
 * `Operator\IndexScan` from at compile time.
 */
final readonly class IndexAccess
{
    /** @param 'eq'|'lt'|'lte'|'gt'|'gte' $operator */
    public function __construct(
        public string $indexName,
        public string $column,
        public string $operator,
        public Expression $value,
    ) {
    }
}

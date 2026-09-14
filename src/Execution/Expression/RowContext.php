<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Expression;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Schema\Row;

/**
 * The one `EvaluationContext` this phase needs: at most one row, from at
 * most one table, plus whatever parameters a statement was given.
 *
 * `$row` is `null` for the cases that have none — an `INSERT ... VALUES`
 * expression (which cannot reference a column) and a column's `DEFAULT`
 * expression (same) — and `column()` refuses cleanly rather than pretend a
 * row exists. `$tableName`/`$tableAlias` are how a qualified reference
 * (`u.id`) is checked: with only one table ever in scope until joins exist
 * (Phase 7), the qualifier just has to name that one table, under either
 * spelling.
 */
final readonly class RowContext implements EvaluationContext
{
    /** @param list<mixed> $parameters */
    public function __construct(
        private ?Row $row = null,
        private ?string $tableName = null,
        private ?string $tableAlias = null,
        private array $parameters = [],
    ) {
    }

    public function column(?string $qualifier, string $name): mixed
    {
        if ($this->row === null) {
            throw new ExecutionException(sprintf('Column "%s" cannot be used here: there is no row in scope.', $name));
        }

        if ($qualifier !== null && $qualifier !== $this->tableName && $qualifier !== $this->tableAlias) {
            throw new ExecutionException(sprintf('Unknown table or alias "%s".', $qualifier));
        }

        if (!$this->row->has($name)) {
            throw new ExecutionException(sprintf('Unknown column "%s".', $name));
        }

        return $this->row->get($name);
    }

    public function parameter(int $index): mixed
    {
        if (!array_key_exists($index, $this->parameters)) {
            throw new ExecutionException(sprintf('No value bound for parameter #%d.', $index + 1));
        }

        return $this->parameters[$index];
    }
}

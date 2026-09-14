<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Expression;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Schema\Row;

/**
 * The `EvaluationContext` for a joined row: one `Row` whose keys are
 * already `"tableRef.column"` for every column of every table involved,
 * built by `Execution\Operator\Qualify` and merged by `NestedLoopJoin`/
 * `HashJoin`. A qualified lookup (`u.id`) is a direct key lookup; an
 * unqualified one (`id`) has to be unambiguous — found on exactly one
 * table — since nothing about a bare column name says which side of a
 * join it came from.
 *
 * `RowContext` stays the context for everything that is not a join (a
 * single-table `SELECT`, `INSERT`/`UPDATE`/`DELETE`, a column default) —
 * this class exists so joined rows get the same, single, obviously-correct
 * qualification logic in one place, not duplicated in `Filter`/`Sort`/
 * `Project` themselves.
 */
final readonly class QualifiedRowContext implements EvaluationContext
{
    /** @param list<mixed> $parameters */
    public function __construct(
        private Row $row,
        private array $parameters = [],
    ) {
    }

    public function column(?string $qualifier, string $name): mixed
    {
        if ($qualifier !== null) {
            $key = $qualifier . '.' . $name;

            if (!$this->row->has($key)) {
                throw new ExecutionException(sprintf('Unknown column "%s".', $key));
            }

            return $this->row->get($key);
        }

        $matches = [];

        foreach ($this->row->columnNames() as $key) {
            if (substr($key, strpos($key, '.') + 1) === $name) {
                $matches[] = $key;
            }
        }

        return match (count($matches)) {
            0 => throw new ExecutionException(sprintf('Unknown column "%s".', $name)),
            1 => $this->row->get($matches[0]),
            default => throw new ExecutionException(sprintf('Column "%s" is ambiguous; qualify it with a table name.', $name)),
        };
    }

    public function parameter(int $index): mixed
    {
        if (!array_key_exists($index, $this->parameters)) {
            throw new ExecutionException(sprintf('No value bound for parameter #%d.', $index + 1));
        }

        return $this->parameters[$index];
    }
}

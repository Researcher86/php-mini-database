<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;

/**
 * Drops a row whose *entire* value tuple already came through once —
 * `SELECT DISTINCT`. Runs after `Project`, so "already came through" means
 * the result's own output columns, not whatever the source table's row
 * looked like before it was projected down to them.
 *
 * The "seen" set grows with the number of distinct tuples, not with the
 * number of source rows — the same unavoidable cost every DISTINCT
 * implementation pays, since nothing shorter than remembering every tuple
 * already emitted can tell a new one apart from a repeat.
 */
final readonly class Distinct implements Operator
{
    public function __construct(
        private Operator $source,
    ) {
    }

    public function getIterator(): Generator
    {
        $seen = [];

        foreach ($this->source as $id => $row) {
            $key = serialize($row->toArray());

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            yield $id => $row;
        }
    }
}

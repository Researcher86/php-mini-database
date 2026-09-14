<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Schema\Row;

/**
 * Re-keys every column of a single-table source as `"ref.column"`, turning
 * an ordinary `SeqScan`/`IndexScan` into something `NestedLoopJoin`/
 * `HashJoin` can merge unambiguously with another table's rows — two
 * tables both named "id" become "u.id" and "o.id", which can coexist in
 * one merged `Row` where two bare "id" keys could not.
 *
 * Every leaf of a `FROM` tree is wrapped in one of these before a join
 * touches it, so a join operator's two inputs are always already-qualified
 * rows, whether a leaf is a plain table or the output of a nested join one
 * level down — a join never needs to know which.
 */
final readonly class Qualify implements Operator
{
    public function __construct(
        private Operator $source,
        private string $reference,
    ) {
    }

    public function getIterator(): Generator
    {
        foreach ($this->source as $id => $row) {
            $qualified = [];

            foreach ($row->toArray() as $column => $value) {
                $qualified[$this->reference . '.' . $column] = $value;
            }

            yield $id => new Row($qualified);
        }
    }
}

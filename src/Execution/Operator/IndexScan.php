<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Storage\BTreeIndex;
use PhpMiniDatabase\Storage\HeapFile;
use PhpMiniDatabase\Storage\RecordId;

/**
 * `SeqScan`'s counterpart for a query a `BTreeIndex` can answer directly:
 * an equality lookup or a bounded range, rather than reading the whole
 * table. The index gives back `RecordId`s in key order; this operator
 * turns each into the row it addresses, the same as `SeqScan` does from a
 * heap scan.
 *
 * Built via `equals()` or `range()` rather than a public constructor with
 * nullable parameters, because "no value" (a range scan) and "the value is
 * `NULL`" (a lookup that will always find nothing — see `BTreeIndex`) are
 * both naturally spelled `null` and have to stay distinguishable.
 *
 * `Executor` decides *when* to build one of these instead of a `SeqScan` —
 * this class only knows how to run the lookup it was given, once that
 * choice has already been made. See DECISIONS.md for the (deliberately
 * small, rule-based) decision itself, standing in for a real planner.
 */
final readonly class IndexScan implements Operator
{
    private function __construct(
        private Table $table,
        private HeapFile $heap,
        private BTreeIndex $index,
        private bool $isEquals,
        private mixed $equalsValue,
        private mixed $low,
        private bool $lowInclusive,
        private mixed $high,
        private bool $highInclusive,
    ) {
    }

    public static function equals(Table $table, HeapFile $heap, BTreeIndex $index, mixed $value): self
    {
        return new self($table, $heap, $index, true, $value, null, true, null, true);
    }

    public static function range(
        Table $table,
        HeapFile $heap,
        BTreeIndex $index,
        mixed $low,
        bool $lowInclusive,
        mixed $high,
        bool $highInclusive,
    ): self {
        return new self($table, $heap, $index, false, null, $low, $lowInclusive, $high, $highInclusive);
    }

    public function getIterator(): Generator
    {
        $ids = $this->isEquals
            ? $this->index->search($this->equalsValue)
            : $this->index->range($this->low, $this->lowInclusive, $this->high, $this->highInclusive);

        /** @var RecordId $id */
        foreach ($ids as $id) {
            $record = $this->heap->read($id);

            if ($record !== null) {
                yield $id => $this->table->deserializeRow($record);
            }
        }
    }
}

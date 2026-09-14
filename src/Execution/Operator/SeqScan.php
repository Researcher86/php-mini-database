<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Storage\HeapFile;
use PhpMiniDatabase\Storage\RecordId;

/**
 * Reads every live row of a table, in whatever physical order `HeapFile`
 * stores them — the only access path until `IndexScan` exists (Phase 6).
 * The generator's keys are the rows' `RecordId`s, carried through in case a
 * caller above this one needs them (nothing in the Phase 5 pipeline does;
 * `UPDATE`/`DELETE` read the heap file directly instead — see
 * DECISIONS.md).
 */
final readonly class SeqScan implements Operator
{
    public function __construct(
        private Table $table,
        private HeapFile $heap,
    ) {
    }

    public function getIterator(): Generator
    {
        /** @var RecordId $id */
        foreach ($this->heap->scan() as $id => $record) {
            yield $id => $this->table->deserializeRow($record);
        }
    }
}

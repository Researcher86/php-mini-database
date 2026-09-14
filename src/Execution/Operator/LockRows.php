<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Transaction\LockManager;
use PhpMiniDatabase\Transaction\LockMode;

/**
 * Acquires a lock on every row a scan visits, before yielding it —
 * REPEATABLE_READ/SERIALIZABLE's read-locking, wired in as a pipeline
 * stage so `SeqScan`/`IndexScan` need not know about transactions at all.
 * Sits directly on top of one of those two, before `Filter`: a repeatable
 * read promises that scanning again with the same predicate later in the
 * same transaction sees the same matching rows, which requires holding a
 * row the predicate rejected just as much as one it kept, in case a later
 * statement in this transaction would have matched it.
 */
final readonly class LockRows implements Operator
{
    public function __construct(
        private Operator $source,
        private LockManager $locks,
        private string $table,
        private int $txId,
        private LockMode $mode,
    ) {
    }

    public function getIterator(): Generator
    {
        /** @var RecordId $id */
        foreach ($this->source as $id => $row) {
            $this->locks->acquireRowLock($this->table, $id, $this->txId, $this->mode);

            yield $id => $row;
        }
    }
}

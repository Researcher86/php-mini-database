<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

use PhpMiniDatabase\Exception\TransactionException;

/**
 * One open transaction's own state: which changes it has made so far (for
 * `ROLLBACK`/`ROLLBACK TO SAVEPOINT` to undo) and where each of its
 * `SAVEPOINT`s falls in that history. `TransactionManager` is what decides
 * *when* to consult this; this class only remembers.
 *
 * Both rolling back to a savepoint and releasing one drop every savepoint
 * declared after it — a point in history that no longer exists cannot be
 * rolled back to either. They differ on the named savepoint itself:
 * rolling back to it leaves it in place, usable again; releasing it
 * forgets it too. Both are standard SQL's own rules for the two.
 */
final class Transaction
{
    /** @var list<WalRecord> every change logged since BEGIN, oldest first */
    private array $log = [];

    /** @var array<string, int> savepoint name => index into $log when it was declared */
    private array $savepoints = [];

    public function __construct(
        public readonly int $id,
        public readonly IsolationLevel $isolationLevel,
    ) {
    }

    public function record(WalRecord $record): void
    {
        $this->log[] = $record;
    }

    public function declareSavepoint(string $name): void
    {
        $this->savepoints[$name] = count($this->log);
    }

    public function hasSavepoint(string $name): bool
    {
        return isset($this->savepoints[$name]);
    }

    /**
     * Every record logged since $name was declared, most-recently-logged
     * first — the order `TransactionManager` needs to undo them in.
     *
     * @return list<WalRecord>
     */
    public function recordsSince(string $name): array
    {
        return array_reverse(array_slice($this->log, $this->savepointIndex($name)));
    }

    /**
     * Drops the log entries made since $name — $name itself survives and
     * can be rolled back to again, only the savepoints declared after it
     * are forgotten, matching `ROLLBACK TO SAVEPOINT`'s standard SQL
     * meaning.
     */
    public function truncateToSavepoint(string $name): void
    {
        $index = $this->savepointIndex($name);
        $this->log = array_slice($this->log, 0, $index);
        $this->forgetSavepointsAfter($index);
    }

    /** Forgets $name itself and every savepoint declared after it, keeping the log itself untouched. */
    public function releaseSavepoint(string $name): void
    {
        $this->forgetSavepointsAfter($this->savepointIndex($name) - 1);
    }

    /** @return list<WalRecord> the whole log, most-recently-logged first */
    public function allRecordsReversed(): array
    {
        return array_reverse($this->log);
    }

    /**
     * Empties the log, once every change in it has actually been undone —
     * `TransactionManager::rollback()` calls this between applying the
     * undo and its own durability barrier, so that a barrier failure
     * leaves a transaction that is still open but has nothing left to
     * undo. Without it, the retry that failure invites would undo every
     * change a second time, and a second delete of an already-deleted row
     * is a `StorageException`, not a no-op — leaving the transaction
     * permanently unable to finish. The savepoints go with it: they index
     * into a log that no longer exists.
     */
    public function forgetAllRecords(): void
    {
        $this->log = [];
        $this->savepoints = [];
    }

    private function savepointIndex(string $name): int
    {
        return $this->savepoints[$name] ?? throw new TransactionException(sprintf('No such savepoint "%s".', $name));
    }

    private function forgetSavepointsAfter(int $index): void
    {
        foreach ($this->savepoints as $name => $declaredAt) {
            if ($declaredAt > $index) {
                unset($this->savepoints[$name]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

use Closure;
use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Storage\RecordId;

/**
 * `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT`, and the crash recovery that
 * shares their undo machinery. Owns the one `Transaction` that can be open
 * at a time — a second `begin()` while one is already active is refused
 * rather than implicitly committing the first, since a silent commit a
 * caller did not ask for is exactly the kind of surprise this project
 * avoids elsewhere.
 *
 * Undoing a change is not this class's job: it holds the *log* of what
 * happened, but reversing a change means writing to the heap file and its
 * indexes, which only `Execution\Executor` knows how to do. `setUndoHandler()`
 * is where that capability is wired in — a `Closure(WalRecord): void` that
 * physically applies one record's reversal — resolving what would
 * otherwise be a circular dependency (`Executor` needs a
 * `TransactionManager` to dispatch `BEGIN`/`COMMIT`/…, and this class needs
 * `Executor`'s row-mutation logic to undo anything) without either class
 * depending on the other's concrete type.
 */
final class TransactionManager
{
    private ?Transaction $current = null;

    private int $nextTransactionId = 1;

    private ?Closure $undo = null;

    private bool $recovered = false;

    public function __construct(
        private readonly Wal $wal,
        private readonly LockManager $locks,
    ) {
    }

    /** @param Closure(WalRecord): void $handler */
    public function setUndoHandler(Closure $handler): void
    {
        $this->undo = $handler;
    }

    public function current(): ?Transaction
    {
        return $this->current;
    }

    public function inTransaction(): bool
    {
        return $this->current !== null;
    }

    public function locks(): LockManager
    {
        return $this->locks;
    }

    public function begin(IsolationLevel $level = IsolationLevel::READ_COMMITTED): Transaction
    {
        if ($this->current !== null) {
            throw new TransactionException('A transaction is already active; COMMIT or ROLLBACK it first.');
        }

        $id = $this->nextTransactionId++;
        $this->wal->append(WalRecord::begin($this->wal->nextLsn(), $id));

        return $this->current = new Transaction($id, $level);
    }

    public function commit(): void
    {
        $tx = $this->requireCurrent();

        $this->wal->append(WalRecord::commit($this->wal->nextLsn(), $tx->id));
        $this->finish($tx);
    }

    public function rollback(): void
    {
        $tx = $this->requireCurrent();

        $this->applyUndo($tx->allRecordsReversed());
        $this->wal->append(WalRecord::rollback($this->wal->nextLsn(), $tx->id));
        $this->finish($tx);
    }

    public function savepoint(string $name): void
    {
        $tx = $this->requireCurrent();

        $tx->declareSavepoint($name);
        $this->wal->append(WalRecord::savepoint($this->wal->nextLsn(), $tx->id, $name));
    }

    public function rollbackToSavepoint(string $name): void
    {
        $tx = $this->requireCurrent();

        $this->applyUndo($tx->recordsSince($name));
        $tx->truncateToSavepoint($name);
        $this->wal->append(WalRecord::rollbackToSavepoint($this->wal->nextLsn(), $tx->id, $name));
    }

    public function releaseSavepoint(string $name): void
    {
        $tx = $this->requireCurrent();

        $tx->releaseSavepoint($name);
        $this->wal->append(WalRecord::releaseSavepoint($this->wal->nextLsn(), $tx->id, $name));
    }

    /**
     * `Executor` calls one of these three once per row mutation, *after*
     * applying it to the heap and its indexes — so the log always reflects
     * a change that has already actually happened, and always carries the
     * mutation's real final `RecordId` (which, for `INSERT` and a moving
     * `UPDATE`, is not knowable before the mutation itself runs). See
     * DECISIONS.md for what that ordering costs.
     *
     * @param array<string, mixed> $after
     */
    public function logInsert(string $table, RecordId $id, array $after): void
    {
        $this->append(fn (int $lsn, int $txId): WalRecord => WalRecord::insert($lsn, $txId, $table, $id, $after));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function logUpdate(string $table, RecordId $id, array $before, array $after): void
    {
        $this->append(fn (int $lsn, int $txId): WalRecord => WalRecord::update($lsn, $txId, $table, $id, $before, $after));
    }

    /** @param array<string, mixed> $before */
    public function logDelete(string $table, RecordId $id, array $before): void
    {
        $this->append(fn (int $lsn, int $txId): WalRecord => WalRecord::delete($lsn, $txId, $table, $id, $before));
    }

    /** @param Closure(int, int): WalRecord $build */
    private function append(Closure $build): void
    {
        $tx = $this->requireCurrent();
        $record = $build($this->wal->nextLsn(), $tx->id);

        $tx->record($record);
        $this->wal->append($record);
    }

    /**
     * Undoes every transaction left open (a `BEGIN` with no matching
     * `COMMIT`) by a previous process that never got to finish it — meant
     * to run once, before any new statement runs. A no-op on every call
     * after the first: `Execution\Executor`'s constructor calls this
     * unconditionally, and a second `Executor` built against a `Database`
     * that already has a transaction open must not have this walk in and
     * undo it — that transaction is merely in progress, not abandoned.
     * Also assumes a single point of failure: it does not defend against a
     * second crash during recovery's own undo pass. See DECISIONS.md.
     */
    public function recover(): void
    {
        if ($this->recovered) {
            return;
        }

        $this->recovered = true;

        /** @var array<int, list<WalRecord>> $byTransaction */
        $byTransaction = [];

        foreach ($this->wal->readAll() as $record) {
            $byTransaction[$record->txId][] = $record;
        }

        foreach ($byTransaction as $txId => $records) {
            $isComplete = array_any(
                $records,
                static fn (WalRecord $r): bool => $r->operation === WalOperation::COMMIT || $r->operation === WalOperation::ROLLBACK,
            );

            if ($isComplete) {
                continue;
            }

            $this->applyUndo($this->replayStillLiveRecords($txId, $records));
        }

        $this->wal->checkpoint();
    }

    /**
     * Rebuilds one crashed transaction's still-live change log by replaying
     * its WAL records through the same `Transaction` state machine
     * `savepoint()`/`rollbackToSavepoint()`/`releaseSavepoint()` drive
     * live, rather than naively undoing every mutation the transaction
     * ever logged: a process that managed to run `ROLLBACK TO SAVEPOINT`
     * before crashing has already undone everything after that savepoint,
     * both on disk and in that now-gone process's memory, so recovery
     * must not undo it a second time — that record is still sitting in
     * the WAL (nothing rewrites history there), but `Transaction::truncateToSavepoint()`
     * is exactly what already drops it from a live rollback's own undo
     * list, so replaying every record through the same calls reproduces
     * the identical, correct set here.
     *
     * @param list<WalRecord> $records
     *
     * @return list<WalRecord>
     */
    private function replayStillLiveRecords(int $txId, array $records): array
    {
        $tx = new Transaction($txId, IsolationLevel::READ_COMMITTED);
        $mutations = [WalOperation::INSERT, WalOperation::UPDATE, WalOperation::DELETE];

        foreach ($records as $record) {
            match (true) {
                in_array($record->operation, $mutations, true) => $tx->record($record),
                $record->operation === WalOperation::SAVEPOINT => $tx->declareSavepoint($this->requireSavepointName($record)),
                $record->operation === WalOperation::ROLLBACK_TO_SAVEPOINT => $tx->truncateToSavepoint($this->requireSavepointName($record)),
                $record->operation === WalOperation::RELEASE_SAVEPOINT => $tx->releaseSavepoint($this->requireSavepointName($record)),
                default => null,
            };
        }

        return $tx->allRecordsReversed();
    }

    private function requireSavepointName(WalRecord $record): string
    {
        return $record->savepoint ?? throw new TransactionException(sprintf(
            'A %s record is missing its savepoint name.',
            $record->operation->value,
        ));
    }

    /**
     * @param array<int, WalRecord> $records not typed `list<WalRecord>`:
     *        `recover()`'s own `array_filter()` before this call preserves
     *        keys, and only a plain `foreach` is needed here, so nothing
     *        is actually lost by not requiring the keys stay contiguous
     */
    private function applyUndo(array $records): void
    {
        $undo = $this->undo ?? throw new TransactionException('No undo handler has been configured.');

        foreach ($records as $record) {
            $undo($record);
        }
    }

    private function finish(Transaction $tx): void
    {
        $this->locks->releaseAll($tx->id);
        $this->current = null;

        // Safe only because exactly one transaction can ever be open at a
        // time in this process - once it ends, nothing depends on the log
        // that got it there.
        $this->wal->checkpoint();
    }

    private function requireCurrent(): Transaction
    {
        return $this->current ?? throw new TransactionException('No transaction is active.');
    }
}

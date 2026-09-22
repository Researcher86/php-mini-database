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
 * `$owner` is who opened the one open `Transaction` — an opaque token the
 * caller supplies (`Execution\Executor` passes `$this`), compared only by
 * identity, never inspected. This is not a second transaction slot: this
 * project stays single-writer (see `begin()`), and any caller can still
 * see whether *something* is open (`inTransaction()`). What owner tracking
 * adds is that only the caller `begin()` recorded may `commit()`/
 * `rollback()`/`savepoint()`/etc. that transaction, or have an autocommit
 * statement silently run inside it — a second `Network\Session` sharing
 * this same `Database` used to be able to do either, since nothing here
 * ever asked which caller was asking. See DECISIONS.md.
 *
 * Undoing a change is not this class's job: it holds the *log* of what
 * happened, but reversing a change means writing to the heap file and its
 * indexes, which only `Execution\Executor` knows how to do. `setUndoHandler()`
 * is where that capability is wired in — a `Closure(WalRecord): void` that
 * physically applies one record's reversal — resolving what would
 * otherwise be a circular dependency (`Executor` needs a
 * `TransactionManager` to dispatch `BEGIN`/`COMMIT`/…, and this class needs
 * `Executor`'s row-mutation logic to undo anything) without either class
 * depending on the other's concrete type. `setSyncHandler()` is the same
 * pattern for a second capability only `Schema\Database` has: pushing
 * every page a transaction touched all the way to the device before its
 * WAL record is checkpointed away. See DECISIONS.md.
 */
final class TransactionManager
{
    private ?Transaction $current = null;

    private mixed $owner = null;

    private int $nextTransactionId = 1;

    private ?Closure $undo = null;

    private ?Closure $sync = null;

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

    /**
     * @param Closure(): void $handler called once, at the end of every
     *        `commit()`/`rollback()`, right before the WAL is checkpointed
     *        — see `Schema\Database::syncStorage()`, the real handler
     *        `Execution\Executor` wires in. Optional: a `TransactionManager`
     *        built without a real `Database` behind it (most of this
     *        class's own tests) has nothing to sync and no handler to
     *        configure.
     */
    public function setSyncHandler(Closure $handler): void
    {
        $this->sync = $handler;
    }

    public function current(): ?Transaction
    {
        return $this->current;
    }

    public function inTransaction(): bool
    {
        return $this->current !== null;
    }

    /** Whether $owner is the caller `begin()` recorded for the currently open transaction — false if nothing is open at all. */
    public function isOwnedBy(mixed $owner): bool
    {
        return $this->current !== null && $this->owner === $owner;
    }

    /** The open transaction, but only if $owner is the one that began it — null otherwise, including when nothing is open. */
    public function currentOwnedBy(mixed $owner): ?Transaction
    {
        return $this->isOwnedBy($owner) ? $this->current : null;
    }

    public function locks(): LockManager
    {
        return $this->locks;
    }

    public function begin(IsolationLevel $level = IsolationLevel::READ_COMMITTED, mixed $owner = null): Transaction
    {
        if ($this->current !== null) {
            throw new TransactionException('A transaction is already active; COMMIT or ROLLBACK it first.');
        }

        $id = $this->nextTransactionId++;
        $this->wal->append(WalRecord::begin($this->wal->nextLsn(), $id));

        $this->owner = $owner;

        return $this->current = new Transaction($id, $level);
    }

    /**
     * `$sync` runs *before* the `COMMIT` record is appended, not after —
     * recovery's only signal that a transaction is finished is that
     * record's presence, so a crash must never be able to show one without
     * the data it covers already being durable. See DECISIONS.md.
     */
    public function commit(mixed $owner = null): void
    {
        $tx = $this->requireOwnedCurrent($owner);

        $this->sync?->__invoke();
        $this->wal->append(WalRecord::commit($this->wal->nextLsn(), $tx->id));
        $this->finish($tx);
    }

    /**
     * @see commit() for why `$sync` runs before the `ROLLBACK` record, not after.
     *
     * The log is emptied between the undo and that barrier, because
     * everything after the undo can still fail and leave this transaction
     * open for the caller to retry: a retry that found the log still
     * populated would undo every change a second time, and the second
     * delete of an already-deleted row is a `StorageException`, which
     * would leave the transaction permanently unable to finish. This is
     * what `rollbackToSavepoint()` has always done with
     * `truncateToSavepoint()` — dropping what it just undid — applied to
     * the whole-transaction case.
     */
    public function rollback(mixed $owner = null): void
    {
        $tx = $this->requireOwnedCurrent($owner);

        $this->applyUndo($tx->allRecordsReversed());
        $tx->forgetAllRecords();
        $this->sync?->__invoke();
        $this->wal->append(WalRecord::rollback($this->wal->nextLsn(), $tx->id));
        $this->finish($tx);
    }

    public function savepoint(string $name, mixed $owner = null): void
    {
        $tx = $this->requireOwnedCurrent($owner);

        $tx->declareSavepoint($name);
        $this->wal->append(WalRecord::savepoint($this->wal->nextLsn(), $tx->id, $name));
    }

    public function rollbackToSavepoint(string $name, mixed $owner = null): void
    {
        $tx = $this->requireOwnedCurrent($owner);

        $this->applyUndo($tx->recordsSince($name));
        $tx->truncateToSavepoint($name);
        $this->wal->append(WalRecord::rollbackToSavepoint($this->wal->nextLsn(), $tx->id, $name));
    }

    public function releaseSavepoint(string $name, mixed $owner = null): void
    {
        $tx = $this->requireOwnedCurrent($owner);

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
     * A crash during this pass is survivable, which it did not used to
     * be: the checkpoint that discards the log comes last, so the WAL
     * still describes the whole transaction, and every undo step asks
     * what is left to do rather than assuming it runs once (see
     * `Execution\Executor::undo()`). The next pass finishes what this
     * one started. What is still assumed sits below this class: that a
     * page write either happened or did not — there are no full-page
     * images here to repair one torn in half. See DECISIONS.md.
     *
     * Syncs storage once, after every abandoned transaction's undo has
     * been applied and before the checkpoint below — the same "data
     * durable before the WAL record that could redo/undo it is discarded"
     * rule `commit()`/`rollback()` follow, applied here to the undo
     * writes this method itself just made.
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

        $this->sync?->__invoke();
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

    /**
     * `commit()`/`rollback()` have already synced storage themselves (see
     * their own docblocks for why that has to happen before their own WAL
     * record, not here) — by the time either reaches this, the checkpoint
     * below is discarding a log nothing still depends on, not the only
     * remaining record of unsynced work.
     */
    private function finish(Transaction $tx): void
    {
        $this->locks->releaseAll($tx->id);
        $this->current = null;
        $this->owner = null;

        // Safe only because exactly one transaction can ever be open at a
        // time in this process - once it ends, nothing depends on the log
        // that got it there.
        $this->wal->checkpoint();
    }

    /**
     * `logInsert()`/`logUpdate()`/`logDelete()`'s own check: no ownership
     * verification, since they only ever run from inside `Execution\Executor::withTransaction()`'s
     * closure, which has already established the caller either began this
     * transaction itself or already owned it before `$work()` ran.
     */
    private function requireCurrent(): Transaction
    {
        return $this->current ?? throw new TransactionException('No transaction is active.');
    }

    private function requireOwnedCurrent(mixed $owner): Transaction
    {
        $tx = $this->requireCurrent();

        if ($this->owner !== $owner) {
            throw new TransactionException(
                'Another connection has an open transaction; only the connection that started it may COMMIT, ROLLBACK, or manage its savepoints.',
            );
        }

        return $tx;
    }
}

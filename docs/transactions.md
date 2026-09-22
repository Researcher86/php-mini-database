# Transactions

`BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT`, isolation levels, the write-ahead
log, and crash recovery — `Transaction\*`, driven by `Execution\Executor`
and owned per-`Schema\Database` (see
[architecture.md](architecture.md#schemadatabase-one-object-per-data-directory)).
The SQL surface is documented in [sql.md](sql.md#statements); this page is
what actually happens underneath it.

## One transaction at a time, and it belongs to whoever opened it

`Transaction\TransactionManager` holds exactly one `Transaction` as
"current" for the whole `Schema\Database` it belongs to — not one per
connection. A `BEGIN` while one is already open throws
`Exception\TransactionException`, whether that came from the same session
or a different one entirely (proven end to end in
[ServerClientTest::testOnlyOneConnectionAtATimeCanHaveAnOpenTransaction](../tests/Integration/ServerClientTest.php)).
This is a direct consequence of `LockManager`'s own design
([DECISIONS.md](DECISIONS.md)): it never waits, so a second transaction can
only ever conflict with the first by being refused outright, and refusing
the second `BEGIN` itself is simplest possible version of that rule.

Being the only transaction open server-wide is not the same as being open
to whoever happens to be asking. `TransactionManager` also records *which*
caller's `begin()` opened it (an opaque owner token — `Execution\Executor`
passes `$this`), and `commit()`/`rollback()`/`savepoint()`/`rollbackToSavepoint()`/
`releaseSavepoint()` all check it: a connection other than the one that
opened the current transaction cannot end it, checkpoint it, or manage its
savepoints, and cannot have a bare autocommit statement of its own silently
run inside it either — `Executor::withTransaction()` rejects that case
outright, the same "refuse rather than wait or guess" rule `LockManager`
already follows. See
[DECISIONS.md](DECISIONS.md#a-transaction-belongs-to-its-owning-connection)
for the bug this closed and
[ServerClientTest::testASecondConnectionCannotJoinOrCommitAnotherConnectionsOpenTransaction](../tests/Integration/ServerClientTest.php)
for it proven against a real server.

Statements outside an explicit `BEGIN` run autocommit — each one is its own
implicit transaction, logged and finished (`TransactionManager::finish()`)
before the next statement starts.

## Isolation levels are locks, not snapshots

`Transaction\IsolationLevel` has three values (`READ_COMMITTED` — the
default, `REPEATABLE_READ`, `SERIALIZABLE`), set via `BEGIN [ISOLATION
LEVEL ...]`. There is no MVCC anywhere in this engine — no row versions, no
transaction snapshot — so "isolation" is entirely a function of which locks
`Executor::lockForRead()` takes and how long they are held:

| Level | Read locking |
|---|---|
| `READ_COMMITTED` | None. A statement simply reads whatever is currently in the heap file. |
| `REPEATABLE_READ` | Every row a statement reads is shared-locked (`LockMode::SHARED`) for the rest of the transaction, so a second read of the same row is guaranteed unchanged. |
| `SERIALIZABLE` | `REPEATABLE_READ`'s row locks, plus: a full table scan (not a single indexed lookup) also takes a shared *table* lock, blocking another transaction from inserting new rows into it — a coarse guard against phantom reads. |

Two gaps are deliberate and named, not silent
([DECISIONS.md](DECISIONS.md)): read-locking only ever triggers inside an
*explicit, still-open* transaction (an autocommit `SELECT` has no later
statement of its own to protect, so it takes no lock at all regardless of
level), and it only covers single-table statements — a `JOIN`'s rows lose
their per-table `RecordId` once merged, so there is nothing to attach a
lock to.

### Because there is no MVCC, reads are dirty by default

Under `READ_COMMITTED` (the default), a row an open transaction has
inserted or changed but not yet committed is visible to every other
connection immediately — there is no snapshot hiding it. Rolling back makes
it disappear again just as immediately. See
[ServerClientTest::testAnUncommittedInsertIsAlreadyVisibleToAnotherConnectionAndDisappearsOnRollback](../tests/Integration/ServerClientTest.php)
for this proven against a real server. Applications that need isolation
from concurrent uncommitted writes need `REPEATABLE_READ`/`SERIALIZABLE`
and to hold their own read lock, not the default.

## Locking

`Transaction\LockManager` grants table and row locks
(`Transaction\LockMode::SHARED`/`EXCLUSIVE`) and never waits: `acquire()`
either succeeds immediately or throws `TransactionException` immediately.
There is no queue, no timeout, no deadlock detector, because there is
nothing to detect a deadlock *for* — the current call is the only thread of
control that could ever release the lock it would otherwise wait on
(`Network\Server`'s single-process event loop; see
[architecture.md](architecture.md#the-server-one-process-an-event-loop-many-sessions)).
A caller that hits a lock conflict gets an exception to retry or surface,
never a hang.

Every write (`INSERT`/`UPDATE`/`DELETE`) takes an exclusive row lock
regardless of isolation level, held until the transaction ends — this is
what actually prevents a lost update, at every level including
`READ_COMMITTED`.

## Savepoints

`SAVEPOINT name` records a position in the current transaction's log
(`Transaction\Transaction::declareSavepoint()`); `ROLLBACK TO SAVEPOINT
name` undoes every change logged since, in reverse order, and forgets every
savepoint declared after it — `name` itself survives and can be rolled back
to again. `RELEASE SAVEPOINT name` forgets `name` (and everything declared
after it) without undoing anything. Both match standard SQL's own rules;
see `Transaction\Transaction`'s docblock for the two operations' exact
difference.

## Write ordering

`Executor::executeInsert()`/`executeUpdate()`/`executeDelete()` write to
the heap file (and its indexes) *first*, and only then call
`TransactionManager::logInsert()`/`logUpdate()`/`logDelete()` — a change is
never logged before it has actually happened. `TransactionManager::logInsert()`
et al. both record the change in the current `Transaction`'s in-memory log
(for `ROLLBACK`/`ROLLBACK TO SAVEPOINT` to undo without touching disk again)
*and* append it to `Transaction\Wal` on disk, `fwrite()` + `fflush()` +
`fsync()`'d before the call returns — every WAL record is durable the
instant it is appended, autocommit or not. This is also this engine's
dominant cost for single-row autocommit writes; see
[InsertBench](../tests/Benchmark/InsertBench.php)'s "batched vs. autocommit"
comparison and the note in [DECISIONS.md](DECISIONS.md).

The narrow crash window this ordering accepts — the exact moment between a
heap/index mutation and its WAL record — is a stated, bounded gap, not an
open-ended one: `commit()`/`rollback()` push every open heap file and
index all the way to the device — `Schema\Database::syncStorage()`, wired
in as `TransactionManager`'s sync handler — *before* appending their own
`COMMIT`/`ROLLBACK` record, not merely before the `checkpoint()` that
follows it. A page `PageManager::write()` left sitting in the OS page
cache after a plain `fwrite()` is not durable on its own; recovery's only
signal that a transaction finished is that `COMMIT`/`ROLLBACK` record's
presence (there is no REDO, only UNDO — see below), so it must never be
able to exist without the data it covers already being durable. `recover()`
follows the same rule for its own undo writes, syncing once after applying
them and before its own checkpoint. See
[DECISIONS.md](DECISIONS.md#commitrollbackrecover-sync-storage-before-their-own-wal-record)
for the two-pass history here — the first fix synced before checkpoint but
after the WAL record, which still left the gap above narrower but open.

## Crash recovery

`Executor`'s constructor unconditionally calls
`TransactionManager::recover()`, and `recover()` itself only ever does real
work once per process (`$this->recovered`, so a second `Executor` opened
against a `Database` that already has a transaction legitimately in
progress does not walk in and undo it). It groups every WAL record by
transaction id; any transaction with no `COMMIT` or `ROLLBACK` record
anywhere in its group is treated as abandoned by a process that crashed (or
was simply never asked to finish it) mid-transaction.

Recovering an abandoned transaction **replays its records through the same
`Transaction` state machine a live session drives** — `record()` for each
mutation, `declareSavepoint()`/`truncateToSavepoint()`/`releaseSavepoint()`
for each savepoint operation — rather than naively undoing every mutation
the transaction ever logged. This matters: a process that managed to run
`ROLLBACK TO SAVEPOINT` before crashing has already undone everything after
that savepoint, both on disk and in memory; recovery must not undo it a
*second* time, since the physical row it would try to reverse is no longer
there. `TransactionManager::replayStillLiveRecords()` reconstructs exactly
the set of still-live changes a crashed process's own `Transaction` object
held at the moment of the crash, and only that set is undone.

`recover()` finishes by syncing storage (its undo writes went through the
same `PageManager::write()` path as any other mutation, so they need the
same durability barrier `commit()`/`rollback()` apply to their own writes
— see "Write ordering" above) and only then checkpointing the WAL
(truncating it, since everything still open has now either been confirmed
complete or undone, and its undo durably applied). See
[TransactionTest](../tests/Integration/TransactionTest.php) for this
proven against real process restarts — an uncommitted transaction undone,
a committed one surviving, and a `ROLLBACK TO SAVEPOINT` immediately before
a crash leaving exactly the pre-savepoint state.

Recovery is single-point-of-failure by design: it does not defend against
a second crash during its own undo pass. A production WAL would make undo
itself idempotent/replayable; this one assumes recovery, once started,
finishes.

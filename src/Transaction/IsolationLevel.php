<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

/**
 * How much of another transaction's concurrent activity a transaction is
 * shielded from. Implemented here through locking (2-phase locking), not
 * row versioning — there is no MVCC in this engine (Phase 2 chose no
 * buffer pool, and nothing since has added multi-version storage), so
 * "repeatable" and "serializable" are achieved by holding the right locks
 * for the whole transaction rather than by reading a frozen snapshot. See
 * DECISIONS.md.
 */
enum IsolationLevel: string
{
    /**
     * The default. A statement sees whatever is currently committed; reads
     * take no lock at all, and only writes are held until commit. That stops
     * two transactions writing one row at once, not a lost read-modify-write
     * update; inside a transaction that is prevented by there being only one
     * open transaction at a time (see `Executor::withTransaction()`).
     */
    case READ_COMMITTED = 'READ COMMITTED';

    /**
     * Like READ COMMITTED, but every row a statement reads is also
     * shared-locked for the rest of the transaction — another transaction
     * cannot change a row this one has already read, so reading it again
     * gives the same answer.
     */
    case REPEATABLE_READ = 'REPEATABLE READ';

    /**
     * Like REPEATABLE READ, and additionally: scanning a whole table
     * (rather than a single indexed row) takes a shared lock on the table
     * itself for the rest of the transaction, so another transaction
     * cannot insert a row into it — a coarse but correct guard against the
     * new rows "phantom reads" are named for.
     */
    case SERIALIZABLE = 'SERIALIZABLE';
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Storage\RecordId;

/**
 * Who holds what lock, in memory, for exactly as long as this `Database`
 * connection is open — nothing here is written to disk, because a lock
 * only ever needs to mean something to the other transactions of the same
 * process that granted it.
 *
 * Acquiring a lock either succeeds immediately or throws immediately —
 * there is no waiting. `Infrastructure\FileLock` polls with a timeout
 * because the process holding a file lock is a *different* process that
 * will eventually release it on its own schedule; a lock this class
 * refuses is held by another `Transaction` inside the *same* synchronous
 * call stack, which cannot release it while this call is still blocked
 * waiting — waiting would just be a hang with a fixed diagnosis. A
 * real wait, against a genuinely concurrent holder, is Phase 12's problem
 * once a server gives more than one connection a thread of control to
 * block in.
 */
final class LockManager
{
    /** @var array<string, array<int, LockMode>> resource key => (txId => mode) */
    private array $grants = [];

    /** @throws TransactionException when an incompatible lock is already held by another transaction */
    public function acquireTableLock(string $table, int $txId, LockMode $mode): void
    {
        $this->acquire('table:' . $table, $txId, $mode, sprintf('table "%s"', $table));
    }

    /** @throws TransactionException when an incompatible lock is already held by another transaction */
    public function acquireRowLock(string $table, RecordId $id, int $txId, LockMode $mode): void
    {
        $this->acquire('row:' . $table . ':' . $id, $txId, $mode, sprintf('row %s in "%s"', $id, $table));
    }

    /** Every lock this transaction holds, released together — 2-phase locking's "shrink phase". */
    public function releaseAll(int $txId): void
    {
        foreach ($this->grants as $resource => $holders) {
            unset($holders[$txId]);

            if ($holders === []) {
                unset($this->grants[$resource]);
            } else {
                $this->grants[$resource] = $holders;
            }
        }
    }

    private function acquire(string $resource, int $txId, LockMode $mode, string $description): void
    {
        $holders = $this->grants[$resource] ?? [];

        if (($holders[$txId] ?? null) === LockMode::EXCLUSIVE) {
            return; // already holds the strongest lock there is
        }

        $others = array_filter($holders, static fn (LockMode $m, int $holder): bool => $holder !== $txId, ARRAY_FILTER_USE_BOTH);

        $compatible = match ($mode) {
            // Exclusive tolerates no other holder at all, not even another
            // shared reader.
            LockMode::EXCLUSIVE => $others === [],
            // Shared tolerates other shared holders, but not an exclusive one.
            LockMode::SHARED => !in_array(LockMode::EXCLUSIVE, $others, true),
        };

        if (!$compatible) {
            throw new TransactionException(sprintf(
                'Cannot acquire a %s lock on %s: held by another transaction.',
                $mode === LockMode::EXCLUSIVE ? 'an exclusive' : 'a shared',
                $description,
            ));
        }

        $holders[$txId] = $mode;
        $this->grants[$resource] = $holders;
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Transaction;

use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PhpMiniDatabase\Transaction\IsolationLevel;
use PhpMiniDatabase\Transaction\LockManager;
use PhpMiniDatabase\Transaction\LockMode;
use PhpMiniDatabase\Transaction\TransactionManager;
use PhpMiniDatabase\Transaction\Wal;
use PhpMiniDatabase\Transaction\WalOperation;
use PhpMiniDatabase\Transaction\WalRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionManagerTest extends TestCase
{
    use TemporaryDirectory;

    private Wal $wal;
    private LockManager $locks;
    private TransactionManager $transactions;

    /** @var list<WalRecord> */
    private array $undone = [];

    /** @return array<string, mixed> */
    private static function afterOf(WalRecord $record): array
    {
        self::assertNotNull($record->after);

        return $record->after;
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->wal = Wal::open($this->path('wal.log'));
        $this->locks = new LockManager();
        $this->transactions = new TransactionManager($this->wal, $this->locks);
        $this->transactions->setUndoHandler(function (WalRecord $record): void {
            $this->undone[] = $record;
        });
    }

    protected function tearDown(): void
    {
        $this->wal->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testBeginReturnsANewTransactionAndLogsIt(): void
    {
        $tx = $this->transactions->begin();

        self::assertTrue($this->transactions->inTransaction());
        self::assertSame($tx, $this->transactions->current());
        self::assertSame(WalOperation::BEGIN, $this->wal->readAll()[0]->operation);
    }

    public function testBeginDefaultsToReadCommitted(): void
    {
        $tx = $this->transactions->begin();

        self::assertSame(IsolationLevel::READ_COMMITTED, $tx->isolationLevel);
    }

    public function testBeginAcceptsAnExplicitIsolationLevel(): void
    {
        $tx = $this->transactions->begin(IsolationLevel::SERIALIZABLE);

        self::assertSame(IsolationLevel::SERIALIZABLE, $tx->isolationLevel);
    }

    public function testANestedBeginIsRejected(): void
    {
        $this->transactions->begin();

        $this->expectException(TransactionException::class);
        $this->transactions->begin();
    }

    public function testCommitEndsTheTransactionAndLogsIt(): void
    {
        $this->transactions->begin();
        $this->transactions->commit();

        self::assertFalse($this->transactions->inTransaction());
        self::assertNull($this->transactions->current());
    }

    public function testCommitWithNoActiveTransactionThrows(): void
    {
        $this->expectException(TransactionException::class);
        $this->transactions->commit();
    }

    public function testCommitChecksPointsTheLog(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['id' => 1]);
        $this->transactions->commit();

        self::assertSame([], $this->wal->readAll());
    }

    public function testCommitReleasesEveryLockTheTransactionHeld(): void
    {
        $tx = $this->transactions->begin();
        $this->locks->acquireRowLock('t', new RecordId(0, 0), $tx->id, LockMode::EXCLUSIVE);

        $this->transactions->commit();

        // If the lock had survived, a different transaction could not
        // take it.
        $this->locks->acquireRowLock('t', new RecordId(0, 0), 999, LockMode::EXCLUSIVE);
        $this->addToAssertionCount(1);
    }

    // --- Ownership ---

    public function testAnotherOwnerCannotCommitTheOpenTransaction(): void
    {
        $this->transactions->begin(owner: 'alice');

        $this->expectException(TransactionException::class);
        $this->transactions->commit(owner: 'bob');
    }

    public function testAnotherOwnerCannotRollBackTheOpenTransaction(): void
    {
        $this->transactions->begin(owner: 'alice');

        $this->expectException(TransactionException::class);
        $this->transactions->rollback(owner: 'bob');
    }

    public function testAnotherOwnerCannotSavepointTheOpenTransaction(): void
    {
        $this->transactions->begin(owner: 'alice');

        $this->expectException(TransactionException::class);
        $this->transactions->savepoint('sp1', owner: 'bob');
    }

    public function testTheOwnerThatBeganItCanStillCommitIt(): void
    {
        $this->transactions->begin(owner: 'alice');
        $this->transactions->commit(owner: 'alice');

        self::assertFalse($this->transactions->inTransaction());
    }

    public function testIsOwnedByIsFalseWhenNothingIsOpen(): void
    {
        self::assertFalse($this->transactions->isOwnedBy('alice'));
    }

    public function testIsOwnedByDistinguishesTheRealOwnerFromAnyoneElse(): void
    {
        $this->transactions->begin(owner: 'alice');

        self::assertTrue($this->transactions->isOwnedBy('alice'));
        self::assertFalse($this->transactions->isOwnedBy('bob'));
    }

    public function testCurrentOwnedByReturnsNullForAnyoneButTheRealOwner(): void
    {
        $tx = $this->transactions->begin(owner: 'alice');

        self::assertSame($tx, $this->transactions->currentOwnedBy('alice'));
        self::assertNull($this->transactions->currentOwnedBy('bob'));
    }

    public function testOwnershipIsForgottenOnceTheTransactionEnds(): void
    {
        $this->transactions->begin(owner: 'alice');
        $this->transactions->commit(owner: 'alice');

        // Bob is not silently now "the owner of nothing" - a fresh begin()
        // by anyone, alice included, must not be blocked by a stale owner.
        $this->transactions->begin(owner: 'bob');
        self::assertTrue($this->transactions->isOwnedBy('bob'));
        self::assertFalse($this->transactions->isOwnedBy('alice'));
    }

    public function testRollbackWithNoActiveTransactionThrows(): void
    {
        $this->expectException(TransactionException::class);
        $this->transactions->rollback();
    }

    public function testRollbackUndoesEveryLoggedChangeMostRecentFirst(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);
        $this->transactions->logInsert('t', new RecordId(0, 1), ['n' => 2]);

        $this->transactions->rollback();

        self::assertSame([2, 1], array_map(static fn (WalRecord $r) => self::afterOf($r)['n'], $this->undone));
    }

    public function testRollbackChecksPointsTheLog(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['id' => 1]);
        $this->transactions->rollback();

        self::assertSame([], $this->wal->readAll());
    }

    public function testSavepointRequiresAnActiveTransaction(): void
    {
        $this->expectException(TransactionException::class);
        $this->transactions->savepoint('sp1');
    }

    public function testRollbackToSavepointUndoesOnlyWhatCameAfterIt(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);
        $this->transactions->savepoint('sp1');
        $this->transactions->logInsert('t', new RecordId(0, 1), ['n' => 2]);

        $this->transactions->rollbackToSavepoint('sp1');

        self::assertSame([2], array_map(static fn (WalRecord $r) => self::afterOf($r)['n'], $this->undone));
    }

    public function testTheTransactionStaysOpenAfterRollingBackToASavepoint(): void
    {
        $this->transactions->begin();
        $this->transactions->savepoint('sp1');
        $this->transactions->rollbackToSavepoint('sp1');

        self::assertTrue($this->transactions->inTransaction());
    }

    public function testRollingBackToTheSameSavepointTwiceWorks(): void
    {
        $this->transactions->begin();
        $this->transactions->savepoint('sp1');
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);
        $this->transactions->rollbackToSavepoint('sp1');
        $this->transactions->logInsert('t', new RecordId(0, 1), ['n' => 2]);
        $this->transactions->rollbackToSavepoint('sp1');

        self::assertSame([1, 2], array_map(static fn (WalRecord $r) => self::afterOf($r)['n'], $this->undone));
    }

    public function testRollbackToAnUnknownSavepointThrows(): void
    {
        $this->transactions->begin();

        $this->expectException(TransactionException::class);
        $this->transactions->rollbackToSavepoint('missing');
    }

    public function testAFullRollbackAfterASavepointUndoesEverything(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);
        $this->transactions->savepoint('sp1');
        $this->transactions->logInsert('t', new RecordId(0, 1), ['n' => 2]);

        $this->transactions->rollback();

        self::assertSame([2, 1], array_map(static fn (WalRecord $r) => self::afterOf($r)['n'], $this->undone));
    }

    public function testReleaseSavepointRequiresAnActiveTransaction(): void
    {
        $this->expectException(TransactionException::class);
        $this->transactions->releaseSavepoint('sp1');
    }

    // --- Recovery ---

    public function testRecoveryUndoesATransactionThatNeverCommitted(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);
        // Simulate a crash: no commit() or rollback() call at all.

        $fresh = new TransactionManager($this->wal, new LockManager());
        $undone = [];
        $fresh->setUndoHandler(function (WalRecord $record) use (&$undone): void {
            $undone[] = $record;
        });

        $fresh->recover();

        self::assertCount(1, $undone);
        self::assertSame(WalOperation::INSERT, $undone[0]->operation);
    }

    public function testRecoveryLeavesACommittedTransactionAlone(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);
        $this->transactions->commit();

        $fresh = new TransactionManager($this->wal, new LockManager());
        $undone = [];
        $fresh->setUndoHandler(function (WalRecord $record) use (&$undone): void {
            $undone[] = $record;
        });

        $fresh->recover();

        self::assertSame([], $undone);
    }

    public function testRecoveryCheckpointsTheLogAfterward(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['n' => 1]);

        $fresh = new TransactionManager($this->wal, new LockManager());
        $fresh->setUndoHandler(static function (WalRecord $record): void {
        });
        $fresh->recover();

        self::assertSame([], $this->wal->readAll());
    }

    public function testRecoveryOnAnEmptyLogIsANoOp(): void
    {
        $this->transactions->recover();

        self::assertSame([], $this->undone);
    }

    // --- Sync handler ---

    /**
     * The stronger guarantee: not merely "sync before checkpoint", but
     * "sync before the COMMIT record exists at all" - recovery's only
     * signal that a transaction is finished is that record's presence, so
     * a sync failure must prevent it from ever being written, not just
     * delay the checkpoint that would otherwise discard it.
     */
    public function testTheSyncHandlerRunsBeforeTheCommitRecordIsEvenWritten(): void
    {
        $this->transactions->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['id' => 1]);

        try {
            $this->transactions->commit();
            self::fail('Expected the sync handler failure to propagate.');
        } catch (RuntimeException) {
            // Expected.
        }

        $operations = array_map(static fn (WalRecord $r): WalOperation => $r->operation, $this->wal->readAll());
        self::assertNotContains(WalOperation::COMMIT, $operations);

        // Still open, from TransactionManager's own point of view too - a
        // failed commit() must not silently finish the transaction.
        self::assertTrue($this->transactions->inTransaction());
    }

    /** @see testTheSyncHandlerRunsBeforeTheCommitRecordIsEvenWritten() - same guarantee, for ROLLBACK. */
    public function testTheSyncHandlerRunsBeforeTheRollbackRecordIsEvenWritten(): void
    {
        $this->transactions->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['id' => 1]);

        try {
            $this->transactions->rollback();
            self::fail('Expected the sync handler failure to propagate.');
        } catch (RuntimeException) {
            // Expected.
        }

        $operations = array_map(static fn (WalRecord $r): WalOperation => $r->operation, $this->wal->readAll());
        self::assertNotContains(WalOperation::ROLLBACK, $operations);
    }

    public function testRecoverySyncsStorageBeforeCheckpointingToo(): void
    {
        $this->transactions->begin();
        $this->transactions->logInsert('t', new RecordId(0, 0), ['id' => 1]);
        // Simulate a crash: no commit() or rollback() call at all.

        $fresh = new TransactionManager($this->wal, new LockManager());
        $fresh->setUndoHandler(static function (WalRecord $record): void {
        });
        $fresh->setSyncHandler(static function (): void {
            throw new RuntimeException('the device is full');
        });

        try {
            $fresh->recover();
            self::fail('Expected the sync handler failure to propagate.');
        } catch (RuntimeException) {
            // Expected.
        }

        // The undo already ran (it runs before the sync call) but the
        // checkpoint that would have discarded the log describing it must
        // not have: a second crash right here still has the WAL to redo
        // recovery from, rather than a silently-lost undo and an empty log.
        self::assertNotSame([], $this->wal->readAll());
    }

    public function testNoSyncHandlerConfiguredIsANoOpNotAnError(): void
    {
        $this->transactions->begin();
        $this->transactions->commit();

        self::assertSame([], $this->wal->readAll());
    }
}

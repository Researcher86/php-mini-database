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

final class TransactionManagerTest extends TestCase
{
    use TemporaryDirectory;

    private Wal $wal;
    private LockManager $locks;
    private TransactionManager $transactions;

    /** @var list<WalRecord> */
    private array $undone = [];

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

        self::assertSame([2, 1], array_map(static fn (WalRecord $r) => $r->after['n'], $this->undone));
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

        self::assertSame([2], array_map(static fn (WalRecord $r) => $r->after['n'], $this->undone));
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

        self::assertSame([1, 2], array_map(static fn (WalRecord $r) => $r->after['n'], $this->undone));
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

        self::assertSame([2, 1], array_map(static fn (WalRecord $r) => $r->after['n'], $this->undone));
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
}

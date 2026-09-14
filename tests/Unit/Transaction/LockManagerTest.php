<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Transaction;

use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Transaction\LockManager;
use PhpMiniDatabase\Transaction\LockMode;
use PHPUnit\Framework\TestCase;

final class LockManagerTest extends TestCase
{
    private LockManager $locks;

    protected function setUp(): void
    {
        $this->locks = new LockManager();
    }

    public function testASingleTransactionCanAcquireASharedThenAnExclusiveLockOnItsOwnRow(): void
    {
        $this->locks->acquireRowLock('t', new RecordId(0, 0), 1, LockMode::SHARED);
        $this->locks->acquireRowLock('t', new RecordId(0, 0), 1, LockMode::EXCLUSIVE);

        $this->addToAssertionCount(1); // did not throw
    }

    public function testTwoTransactionsCanShareTheSameRow(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::SHARED);
        $this->locks->acquireRowLock('t', $id, 2, LockMode::SHARED);

        $this->addToAssertionCount(1);
    }

    public function testAnExclusiveLockExcludesEvenAnotherSharedHolder(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::SHARED);

        $this->expectException(TransactionException::class);
        $this->locks->acquireRowLock('t', $id, 2, LockMode::EXCLUSIVE);
    }

    public function testAnExclusiveLockExcludesAnotherExclusiveHolder(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::EXCLUSIVE);

        $this->expectException(TransactionException::class);
        $this->locks->acquireRowLock('t', $id, 2, LockMode::EXCLUSIVE);
    }

    public function testASharedLockIsRefusedWhileAnotherTransactionHoldsExclusive(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::EXCLUSIVE);

        $this->expectException(TransactionException::class);
        $this->locks->acquireRowLock('t', $id, 2, LockMode::SHARED);
    }

    public function testUpgradingFromSharedToExclusiveFailsWhileAnotherTransactionAlsoHoldsShared(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::SHARED);
        $this->locks->acquireRowLock('t', $id, 2, LockMode::SHARED);

        $this->expectException(TransactionException::class);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::EXCLUSIVE);
    }

    public function testReleaseAllFreesEveryResourceForThatTransaction(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::EXCLUSIVE);
        $this->locks->acquireTableLock('t', 1, LockMode::SHARED);

        $this->locks->releaseAll(1);

        $this->locks->acquireRowLock('t', $id, 2, LockMode::EXCLUSIVE);
        $this->locks->acquireTableLock('t', 2, LockMode::EXCLUSIVE);

        $this->addToAssertionCount(1);
    }

    public function testReleasingATransactionThatHeldNoLocksIsHarmless(): void
    {
        $this->locks->releaseAll(1);

        $this->addToAssertionCount(1);
    }

    public function testTableAndRowLocksAreIndependentResources(): void
    {
        $this->locks->acquireTableLock('t', 1, LockMode::EXCLUSIVE);

        // A row lock on a table another transaction holds an exclusive
        // TABLE lock on is a different resource entirely in this model.
        $this->locks->acquireRowLock('t', new RecordId(0, 0), 2, LockMode::EXCLUSIVE);

        $this->addToAssertionCount(1);
    }

    public function testRepeatedlyAcquiringTheSameLockIsIdempotent(): void
    {
        $id = new RecordId(0, 0);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::EXCLUSIVE);
        $this->locks->acquireRowLock('t', $id, 1, LockMode::EXCLUSIVE);

        $this->addToAssertionCount(1);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Transaction;

use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Transaction\IsolationLevel;
use PhpMiniDatabase\Transaction\Transaction;
use PhpMiniDatabase\Transaction\WalRecord;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    private function insertRecord(int $lsn): WalRecord
    {
        return WalRecord::insert($lsn, 1, 't', new RecordId(0, $lsn), ['n' => $lsn]);
    }

    public function testCarriesItsIdAndIsolationLevel(): void
    {
        $tx = new Transaction(7, IsolationLevel::SERIALIZABLE);

        self::assertSame(7, $tx->id);
        self::assertSame(IsolationLevel::SERIALIZABLE, $tx->isolationLevel);
    }

    public function testAllRecordsReversedReturnsMostRecentFirst(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->record($this->insertRecord(1));
        $tx->record($this->insertRecord(2));

        $reversed = $tx->allRecordsReversed();

        self::assertSame(2, $reversed[0]->lsn);
        self::assertSame(1, $reversed[1]->lsn);
    }

    public function testRecordsSinceASavepointExcludesWhatCameBeforeIt(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->record($this->insertRecord(1));
        $tx->declareSavepoint('sp1');
        $tx->record($this->insertRecord(2));
        $tx->record($this->insertRecord(3));

        $since = $tx->recordsSince('sp1');

        self::assertSame([3, 2], array_map(static fn (WalRecord $r): int => $r->lsn, $since));
    }

    public function testASavepointDeclaredImmediatelySeesNothingSinceItYet(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->record($this->insertRecord(1));
        $tx->declareSavepoint('sp1');

        self::assertSame([], $tx->recordsSince('sp1'));
    }

    public function testAnUnknownSavepointThrows(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);

        $this->expectException(TransactionException::class);
        $tx->recordsSince('missing');
    }

    public function testTruncateToSavepointDropsLaterRecords(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->record($this->insertRecord(1));
        $tx->declareSavepoint('sp1');
        $tx->record($this->insertRecord(2));

        $tx->truncateToSavepoint('sp1');

        self::assertSame([1], array_map(static fn (WalRecord $r): int => $r->lsn, $tx->allRecordsReversed()));
    }

    /**
     * ROLLBACK TO SAVEPOINT keeps the savepoint itself alive, matching
     * standard SQL - a transaction can roll back to the same savepoint
     * more than once.
     */
    public function testTruncateToSavepointKeepsTheSavepointItselfUsable(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->declareSavepoint('sp1');
        $tx->record($this->insertRecord(1));
        $tx->truncateToSavepoint('sp1');

        self::assertTrue($tx->hasSavepoint('sp1'));
        self::assertSame([], $tx->recordsSince('sp1'));
    }

    public function testTruncateToSavepointForgetsLaterSavepointsToo(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->declareSavepoint('sp1');
        $tx->record($this->insertRecord(1));
        $tx->declareSavepoint('sp2');

        $tx->truncateToSavepoint('sp1');

        self::assertFalse($tx->hasSavepoint('sp2'));
        self::assertTrue($tx->hasSavepoint('sp1'));
    }

    public function testReleaseSavepointForgetsItButKeepsTheLog(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->declareSavepoint('sp1');
        $tx->record($this->insertRecord(1));

        $tx->releaseSavepoint('sp1');

        self::assertFalse($tx->hasSavepoint('sp1'));
        self::assertCount(1, $tx->allRecordsReversed());
    }

    public function testReleaseSavepointAlsoForgetsLaterSavepoints(): void
    {
        $tx = new Transaction(1, IsolationLevel::READ_COMMITTED);
        $tx->declareSavepoint('sp1');
        $tx->declareSavepoint('sp2');

        $tx->releaseSavepoint('sp1');

        self::assertFalse($tx->hasSavepoint('sp2'));
    }
}

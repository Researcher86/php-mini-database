<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Transaction;

use DateTimeImmutable;
use DateTimeZone;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PhpMiniDatabase\Transaction\Wal;
use PhpMiniDatabase\Transaction\WalOperation;
use PhpMiniDatabase\Transaction\WalRecord;
use PHPUnit\Framework\TestCase;

final class WalTest extends TestCase
{
    use TemporaryDirectory;

    private Wal $wal;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->wal = Wal::open($this->path('wal.log'));
    }

    protected function tearDown(): void
    {
        $this->wal->close();
        $this->tearDownTemporaryDirectory();
    }

    public function testANewLogIsEmpty(): void
    {
        self::assertSame([], $this->wal->readAll());
    }

    public function testLsnsStartAtOneAndIncrement(): void
    {
        self::assertSame(1, $this->wal->nextLsn());
        self::assertSame(2, $this->wal->nextLsn());
        self::assertSame(3, $this->wal->nextLsn());
    }

    public function testAppendedRecordsReadBackInOrder(): void
    {
        $this->wal->append(WalRecord::begin(1, 100));
        $this->wal->append(WalRecord::insert(2, 100, 'users', new RecordId(0, 0), ['id' => 1, 'name' => 'alice']));
        $this->wal->append(WalRecord::commit(3, 100));

        $records = $this->wal->readAll();

        self::assertCount(3, $records);
        self::assertSame([WalOperation::BEGIN, WalOperation::INSERT, WalOperation::COMMIT], array_map(
            static fn (WalRecord $r): WalOperation => $r->operation,
            $records,
        ));
    }

    public function testInsertRecordRoundTripsItsAfterValues(): void
    {
        $id = new RecordId(3, 7);
        $this->wal->append(WalRecord::insert(1, 100, 'users', $id, ['id' => 1, 'name' => 'alice']));

        $record = $this->wal->readAll()[0];

        self::assertSame('users', $record->table);
        self::assertNotNull($record->recordId);
        self::assertTrue($id->equals($record->recordId));
        self::assertSame(['id' => 1, 'name' => 'alice'], $record->after);
        self::assertNull($record->before);
    }

    public function testUpdateRecordRoundTripsBothBeforeAndAfter(): void
    {
        $this->wal->append(WalRecord::update(
            1,
            100,
            'users',
            new RecordId(0, 0),
            ['age' => 30],
            ['age' => 31],
        ));

        $record = $this->wal->readAll()[0];

        self::assertSame(['age' => 30], $record->before);
        self::assertSame(['age' => 31], $record->after);
    }

    public function testDateTimeValuesRoundTripExactly(): void
    {
        $when = new DateTimeImmutable('2024-06-15 12:30:45.123456', new DateTimeZone('UTC'));
        $this->wal->append(WalRecord::insert(1, 100, 't', new RecordId(0, 0), ['at' => $when]));

        $record = $this->wal->readAll()[0];

        self::assertNotNull($record->after);
        self::assertSame('2024-06-15 12:30:45.123456', $record->after['at']);
    }

    public function testBinaryBlobValuesRoundTripExactly(): void
    {
        $bytes = "\xff\xfe\x00\x01binary";
        $this->wal->append(WalRecord::insert(1, 100, 't', new RecordId(0, 0), ['data' => $bytes]));

        $record = $this->wal->readAll()[0];

        self::assertNotNull($record->after);
        self::assertSame($bytes, $record->after['data']);
    }

    public function testSavepointRecordRoundTrips(): void
    {
        $this->wal->append(WalRecord::savepoint(1, 100, 'sp1'));

        $record = $this->wal->readAll()[0];

        self::assertSame(WalOperation::SAVEPOINT, $record->operation);
        self::assertSame('sp1', $record->savepoint);
    }

    public function testTheLogSurvivesReopening(): void
    {
        $this->wal->append(WalRecord::begin(1, 100));

        $reopened = Wal::open($this->path('wal.log'));

        self::assertCount(1, $reopened->readAll());
        self::assertSame(2, $reopened->nextLsn());

        $reopened->close();
    }

    public function testCheckpointDiscardsEveryRecord(): void
    {
        $this->wal->append(WalRecord::begin(1, 100));
        $this->wal->append(WalRecord::commit(2, 100));

        $this->wal->checkpoint();

        self::assertSame([], $this->wal->readAll());
    }

    public function testLsnContinuesAfterAReopenRatherThanRestarting(): void
    {
        $this->wal->append(WalRecord::begin($this->wal->nextLsn(), 100));
        $this->wal->append(WalRecord::begin($this->wal->nextLsn(), 200));
        $this->wal->close();

        $this->wal = Wal::open($this->path('wal.log'));

        self::assertSame(3, $this->wal->nextLsn());
    }

    /**
     * A record whose write a crash cut in half is not part of the log —
     * every complete `append()` is `fsync()`'d, so a malformed *last*
     * line can only be one that never finished being written. Failing the
     * read instead would leave a WAL that cannot be opened, and therefore
     * a database that can never be opened again.
     */
    public function testATornFinalRecordEndsTheLogRatherThanFailingTheRead(): void
    {
        $this->wal->append(WalRecord::begin(1, 1));
        $this->wal->close();

        file_put_contents($this->path('wal.log'), '{"lsn":2,"tx":1,"op":"INS', FILE_APPEND);

        $this->wal = Wal::open($this->path('wal.log'));
        $records = $this->wal->readAll();

        self::assertCount(1, $records);
        self::assertSame(WalOperation::BEGIN, $records[0]->operation);
    }

    /** A malformed line that is *not* the last one is real corruption, and still says so. */
    public function testAMalformedRecordBeforeTheEndIsReportedAsCorruption(): void
    {
        file_put_contents(
            $this->path('corrupt.log'),
            "{\"lsn\":1,\"tx\":1,\"op\":\"BE\n{\"lsn\":2,\"tx\":1,\"op\":\"COMMIT\"}\n",
        );

        // open() reads the log itself, to work out the next LSN.
        $this->expectException(StorageException::class);
        Wal::open($this->path('corrupt.log'));
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

use PhpMiniDatabase\Storage\RecordId;

/**
 * One entry in the write-ahead log: enough to redo *and* to undo one
 * change. `$before`/`$after` hold a row's values by column name, exactly
 * as `Schema\Row::toArray()` gives them — `Wal` is what makes those
 * JSON-safe on the way to and from disk (see its own docblock for why a
 * plain `json_encode()` is not enough).
 *
 * This is a logical record, not a physical one: it says "row X changed
 * from this to that," not which bytes of which page moved. `Undo` (Phase
 * 8's rollback and recovery) replays that logical change in reverse
 * against the live heap and indexes — through the same `Table`/`HeapFile`
 * machinery a fresh `INSERT`/`UPDATE`/`DELETE` would use — rather than
 * restoring raw page bytes the way a physical WAL would.
 */
final readonly class WalRecord
{
    /**
     * @param ?array<string, mixed> $before row values before the change (UPDATE, DELETE)
     * @param ?array<string, mixed> $after  row values after the change (INSERT, UPDATE)
     */
    private function __construct(
        public int $lsn,
        public int $txId,
        public WalOperation $operation,
        public ?string $table = null,
        public ?RecordId $recordId = null,
        public ?array $before = null,
        public ?array $after = null,
        public ?string $savepoint = null,
    ) {
    }

    public static function begin(int $lsn, int $txId): self
    {
        return new self($lsn, $txId, WalOperation::BEGIN);
    }

    public static function commit(int $lsn, int $txId): self
    {
        return new self($lsn, $txId, WalOperation::COMMIT);
    }

    public static function rollback(int $lsn, int $txId): self
    {
        return new self($lsn, $txId, WalOperation::ROLLBACK);
    }

    /** @param array<string, mixed> $after */
    public static function insert(int $lsn, int $txId, string $table, RecordId $recordId, array $after): self
    {
        return new self($lsn, $txId, WalOperation::INSERT, $table, $recordId, after: $after);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function update(int $lsn, int $txId, string $table, RecordId $recordId, array $before, array $after): self
    {
        return new self($lsn, $txId, WalOperation::UPDATE, $table, $recordId, $before, $after);
    }

    /** @param array<string, mixed> $before */
    public static function delete(int $lsn, int $txId, string $table, RecordId $recordId, array $before): self
    {
        return new self($lsn, $txId, WalOperation::DELETE, $table, $recordId, before: $before);
    }

    public static function savepoint(int $lsn, int $txId, string $name): self
    {
        return new self($lsn, $txId, WalOperation::SAVEPOINT, savepoint: $name);
    }

    public static function releaseSavepoint(int $lsn, int $txId, string $name): self
    {
        return new self($lsn, $txId, WalOperation::RELEASE_SAVEPOINT, savepoint: $name);
    }

    public static function rollbackToSavepoint(int $lsn, int $txId, string $name): self
    {
        return new self($lsn, $txId, WalOperation::ROLLBACK_TO_SAVEPOINT, savepoint: $name);
    }

    /**
     * The one general-purpose constructor, for `Wal::decodeRecord()` to
     * rebuild whatever shape a log line held — every other caller uses one
     * of the shape-specific factories above instead, so a record is never
     * built with a combination (a `SAVEPOINT` carrying `$before`, say) that
     * does not correspond to a real operation.
     *
     * @param ?array<string, mixed> $before
     * @param ?array<string, mixed> $after
     */
    public static function reconstruct(
        int $lsn,
        int $txId,
        WalOperation $operation,
        ?string $table,
        ?RecordId $recordId,
        ?array $before,
        ?array $after,
        ?string $savepoint,
    ): self {
        return new self($lsn, $txId, $operation, $table, $recordId, $before, $after, $savepoint);
    }
}

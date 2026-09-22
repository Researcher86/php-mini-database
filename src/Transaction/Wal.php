<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

use DateTimeImmutable;
use JsonException;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Storage\RecordId;

/**
 * The write-ahead log: one growing file of JSON-lines records, in the
 * shape PLAN.md §6.5 shows — `{"lsn":1,"tx":1,"op":"BEGIN"}` and so on —
 * one record per line, each carrying its own sequence number.
 *
 * Every write goes through `append()` before the corresponding change
 * reaches the heap file, and every `append()` is flushed and `fsync()`'d
 * before it returns — the log line for a change exists on disk before the
 * change itself is made, which is the one property "write-ahead" promises
 * and the one thing recovery depends on.
 *
 * A row value can be a `DateTimeImmutable` or an arbitrary byte string
 * (`BLOB`), neither of which `json_encode()` handles on its own — a date
 * has no native JSON representation, and a byte string that is not valid
 * UTF-8 makes `json_encode()` fail outright. Both are wrapped in a small
 * tagged object (`{"__datetime__": "..."}`, `{"__base64__": "..."}`) on
 * the way out and unwrapped on the way in, so the log can hold exactly the
 * values `Schema\Row` does, not merely the ones JSON supports directly.
 *
 * There is no log rotation — `PLAN.md`'s own layout shows numbered
 * segments (`wal.0001.log`, `wal.0002.log`), but this implementation keeps
 * one file and reclaims space by truncating it outright once
 * `TransactionManager` confirms nothing is still relying on it (see
 * `checkpoint()` and DECISIONS.md), the same "space comes back only on an
 * explicit action" trade `HeapFile`'s `VACUUM` and `BTreeIndex`'s lack of
 * rebalancing already made elsewhere in this project.
 */
final class Wal
{
    /** @var resource */
    private mixed $handle;

    private int $nextLsn;

    public function __construct(
        private readonly string $path,
        private readonly FileSystem $files = new FileSystem(),
    ) {
        $this->handle = $this->files->openReadWrite($path);
        $this->nextLsn = $this->computeNextLsn();
    }

    public static function open(string $path, FileSystem $files = new FileSystem()): self
    {
        return new self($path, $files);
    }

    public function nextLsn(): int
    {
        return $this->nextLsn++;
    }

    public function append(WalRecord $record): void
    {
        $line = json_encode($this->encodeRecord($record), JSON_THROW_ON_ERROR) . "\n";

        if (fwrite($this->handle, $line) !== strlen($line) || !fflush($this->handle) || !fsync($this->handle)) {
            throw new StorageException(sprintf('Cannot append to the WAL at "%s".', $this->path));
        }
    }

    /**
     * Every record currently in the log, in LSN order.
     *
     * A malformed *last* line ends the log rather than failing the read:
     * this file is append-only and every complete `append()` is `fsync()`'d,
     * so the only way to produce one is a write that a crash cut in half —
     * and a record that never finished being written is, correctly, not
     * part of the log. Anywhere else a malformed line is real corruption
     * and still throws. Without this, a torn final write would leave a WAL
     * that `Wal::open()` cannot read, and therefore a database that can
     * never be opened again.
     *
     * @return list<WalRecord>
     */
    public function readAll(): array
    {
        $lines = array_values(array_filter(
            explode("\n", $this->files->read($this->path)),
            static fn (string $line): bool => trim($line) !== '',
        ));

        $records = [];

        foreach ($lines as $index => $line) {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                if ($index === count($lines) - 1) {
                    break;
                }

                throw new StorageException(
                    sprintf('The WAL at "%s" is corrupt at record %d.', $this->path, $index + 1),
                    previous: $e,
                );
            }

            $records[] = $this->decodeRecord($decoded);
        }

        return $records;
    }

    /**
     * Discards every record currently in the log. Only ever safe to call
     * when no transaction is open — a truncated log has nothing left to
     * recover an interrupted transaction from — which is
     * `TransactionManager`'s call to make, not this class's.
     */
    public function checkpoint(): void
    {
        // `fsync()` for the same reason `append()` does it: until the
        // truncation reaches the device, the records it dropped are still
        // there, and the next `append()` writes over them from offset 0 -
        // leaving, if a crash lands in between, new records followed by
        // the tail of older ones. Every line of that mixture parses, so
        // nothing would notice.
        if (!ftruncate($this->handle, 0) || fseek($this->handle, 0) !== 0 || !fflush($this->handle) || !fsync($this->handle)) {
            throw new StorageException(sprintf('Cannot checkpoint the WAL at "%s".', $this->path));
        }
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    private function computeNextLsn(): int
    {
        $records = $this->readAll();

        if ($records === []) {
            return 1;
        }

        return max(array_map(static fn (WalRecord $r): int => $r->lsn, $records)) + 1;
    }

    /** @return array<string, mixed> */
    private function encodeRecord(WalRecord $record): array
    {
        return array_filter([
            'lsn' => $record->lsn,
            'tx' => $record->txId,
            'op' => $record->operation->value,
            'table' => $record->table,
            'row_id' => $record->recordId === null ? null : (string) $record->recordId,
            'before' => $record->before === null ? null : $this->encodeValues($record->before),
            'after' => $record->after === null ? null : $this->encodeValues($record->after),
            'savepoint' => $record->savepoint,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $data */
    private function decodeRecord(array $data): WalRecord
    {
        return WalRecord::reconstruct(
            (int) $data['lsn'],
            (int) $data['tx'],
            WalOperation::from($data['op']),
            $data['table'] ?? null,
            isset($data['row_id']) ? RecordId::fromString($data['row_id']) : null,
            isset($data['before']) ? $this->decodeValues($data['before']) : null,
            isset($data['after']) ? $this->decodeValues($data['after']) : null,
            $data['savepoint'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function encodeValues(array $values): array
    {
        return array_map($this->encodeValue(...), $values);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function decodeValues(array $values): array
    {
        return array_map($this->decodeValue(...), $values);
    }

    private function encodeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeImmutable) {
            return ['__datetime__' => $value->format('Y-m-d H:i:s.u')];
        }

        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['__base64__' => base64_encode($value)];
        }

        return $value;
    }

    private function decodeValue(mixed $value): mixed
    {
        if (is_array($value) && isset($value['__datetime__'])) {
            return $value['__datetime__'];
        }

        if (is_array($value) && isset($value['__base64__'])) {
            return base64_decode($value['__base64__'], true);
        }

        return $value;
    }
}

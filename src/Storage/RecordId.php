<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use PhpMiniDatabase\Exception\StorageException;
use Stringable;

/**
 * Where a row lives: a page number and a slot number within it.
 *
 * This is the database's physical row address — what a B-Tree leaf stores
 * against a key, what the WAL names when it records a change, and what an
 * executor carries between operators. It is deliberately not the primary
 * key: a row keeps its RecordId when its key changes, and gets a new one
 * when it has to move to another page.
 *
 * The string form "page:slot" exists so an id can go into a WAL record, a
 * log line or an error message without a codec.
 */
final readonly class RecordId implements Stringable
{
    public function __construct(
        public int $pageId,
        public int $slot,
    ) {
    }

    public static function fromString(string $id): self
    {
        if (preg_match('/^(\d+):(\d+)$/', $id, $m) !== 1) {
            throw new StorageException(sprintf('Malformed record id "%s".', $id));
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    public function equals(self $other): bool
    {
        return $this->pageId === $other->pageId && $this->slot === $other->slot;
    }

    public function __toString(): string
    {
        return $this->pageId . ':' . $this->slot;
    }
}

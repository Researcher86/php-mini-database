<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/**
 * A chunk of a bulk `INSERT`. PLAN.md names the message but not a row
 * format for it — bulk import belongs to Milestone 19 (Backup, Dump,
 * Restore), which is where that format gets designed. Until then this
 * carries opaque bytes, so the *frame* format is already settled and
 * `Codec` already round-trips it; only what is inside is still open.
 */
final readonly class CopyIn implements Message
{
    public function __construct(
        public string $data,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::COPY_IN;
    }

    public function payload(): string
    {
        return $this->data;
    }

    public static function fromPayload(string $bytes): self
    {
        return new self($bytes);
    }
}

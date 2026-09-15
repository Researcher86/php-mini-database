<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** `ROLLBACK` over the wire — no payload. See `Commit`'s docblock. */
final readonly class Rollback implements Message
{
    public function type(): MessageType
    {
        return MessageType::ROLLBACK;
    }

    public function payload(): string
    {
        return '';
    }

    public static function fromPayload(string $bytes): self
    {
        return new self();
    }
}

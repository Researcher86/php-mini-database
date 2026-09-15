<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** `Ping`'s answer. No payload. */
final readonly class Pong implements Message
{
    public function type(): MessageType
    {
        return MessageType::PONG;
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

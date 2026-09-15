<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Asks the server for its connection list. No payload; see `ShowStatus`. */
final readonly class ShowConnections implements Message
{
    public function type(): MessageType
    {
        return MessageType::SHOW_CONNECTIONS;
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

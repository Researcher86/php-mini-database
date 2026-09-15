<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/**
 * `COMMIT` over the wire — no payload. What it commits and how it is
 * acknowledged is a connection's session state, wired up in Milestone 15;
 * this class only carries the message itself.
 */
final readonly class Commit implements Message
{
    public function type(): MessageType
    {
        return MessageType::COMMIT;
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

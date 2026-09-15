<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/**
 * Asks the server to stop whatever this connection is currently running.
 * No payload — unlike PostgreSQL's out-of-band cancel (a second connection
 * carrying a secret token), this protocol has no concurrent-session model
 * yet for `CANCEL` to need one: it always means "the query I, this
 * connection, am mid-way through," decided once a real `Network\Session`
 * exists to be mid-way through anything (Milestone 12).
 */
final readonly class Cancel implements Message
{
    public function type(): MessageType
    {
        return MessageType::CANCEL;
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

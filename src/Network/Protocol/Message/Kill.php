<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Asks the server to close another connection, named by its session id. */
final readonly class Kill implements Message
{
    public function __construct(
        public int $connectionId,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::KILL;
    }

    public function payload(): string
    {
        return pack('N', $this->connectionId);
    }

    public static function fromPayload(string $bytes): self
    {
        if (strlen($bytes) < 4) {
            throw new ProtocolException('KILL message is missing its connection id.');
        }

        return new self(unpack('N', $bytes)[1]);
    }
}

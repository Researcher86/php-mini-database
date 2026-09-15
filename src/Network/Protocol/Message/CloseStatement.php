<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Releases a prepared statement the server no longer needs to keep. */
final readonly class CloseStatement implements Message
{
    public function __construct(
        public int $statementId,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::CLOSE_STMT;
    }

    public function payload(): string
    {
        return pack('N', $this->statementId);
    }

    public static function fromPayload(string $bytes): self
    {
        if (strlen($bytes) < 4) {
            throw new ProtocolException('CLOSE_STMT message is missing its statement id.');
        }

        return new self(unpack('N', $bytes)[1]);
    }
}

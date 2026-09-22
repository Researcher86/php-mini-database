<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Support\Binary;

/** The handle `Execute`/`CloseStatement` name this prepared statement by. */
final readonly class PrepareOk implements Message
{
    public function __construct(
        public int $statementId,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::PREPARE_OK;
    }

    public function payload(): string
    {
        return pack('N', $this->statementId);
    }

    public static function fromPayload(string $bytes): self
    {
        if (strlen($bytes) < 4) {
            throw new ProtocolException('PREPARE_OK message is missing its statement id.');
        }

        return new self(Binary::unpackInt('N', $bytes));
    }
}

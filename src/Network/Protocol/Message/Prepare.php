<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Asks the server to prepare `$sql` without running it yet. */
final readonly class Prepare implements Message
{
    use WireStrings;

    public function __construct(
        public string $sql,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::PREPARE;
    }

    public function payload(): string
    {
        return self::encodeString($this->sql);
    }

    public static function fromPayload(string $bytes): self
    {
        [$sql] = self::decodeString($bytes, 0);

        return new self($sql);
    }
}

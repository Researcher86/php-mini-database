<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** `SAVEPOINT name` over the wire. */
final readonly class Savepoint implements Message
{
    use WireStrings;

    public function __construct(
        public string $name,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::SAVEPOINT;
    }

    public function payload(): string
    {
        return self::encodeString($this->name);
    }

    public static function fromPayload(string $bytes): self
    {
        [$name] = self::decodeString($bytes, 0);

        return new self($name);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** The server's half of the handshake — PLAN.md §5.4. */
final readonly class HelloAck implements Message
{
    use WireStrings;

    /** @param list<string> $capabilities */
    public function __construct(
        public string $serverVersion,
        public string $authMethod,
        public string $salt,
        public array $capabilities = [],
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::HELLO_ACK;
    }

    public function payload(): string
    {
        return self::encodeString($this->serverVersion)
            . self::encodeString($this->authMethod)
            . self::encodeString($this->salt)
            . self::encodeStringList($this->capabilities);
    }

    public static function fromPayload(string $bytes): self
    {
        [$serverVersion, $offset] = self::decodeString($bytes, 0);
        [$authMethod, $offset] = self::decodeString($bytes, $offset);
        [$salt, $offset] = self::decodeString($bytes, $offset);
        [$capabilities] = self::decodeStringList($bytes, $offset);

        return new self($serverVersion, $authMethod, $salt, $capabilities);
    }
}

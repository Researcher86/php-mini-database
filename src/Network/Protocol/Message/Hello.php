<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Opens the handshake — PLAN.md §5.4. */
final readonly class Hello implements Message
{
    use WireStrings;

    /** @param list<string> $capabilities */
    public function __construct(
        public int $protocolVersion,
        public string $clientName,
        public string $clientVersion,
        public array $capabilities = [],
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::HELLO;
    }

    public function payload(): string
    {
        return pack('n', $this->protocolVersion)
            . self::encodeString($this->clientName)
            . self::encodeString($this->clientVersion)
            . self::encodeStringList($this->capabilities);
    }

    public static function fromPayload(string $bytes): self
    {
        if (strlen($bytes) < 2) {
            throw new ProtocolException('HELLO message is missing its protocol version.');
        }

        $protocolVersion = unpack('n', $bytes, 0)[1];
        [$clientName, $offset] = self::decodeString($bytes, 2);
        [$clientVersion, $offset] = self::decodeString($bytes, $offset);
        [$capabilities] = self::decodeStringList($bytes, $offset);

        return new self($protocolVersion, $clientName, $clientVersion, $capabilities);
    }
}

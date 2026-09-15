<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Authentication succeeded — PLAN.md §5.4. `$serverTime` is Unix seconds. */
final readonly class AuthOk implements Message
{
    use WireStrings;

    public function __construct(
        public string $sessionId,
        public int $serverTime,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::AUTH_OK;
    }

    public function payload(): string
    {
        return self::encodeString($this->sessionId) . pack('J', $this->serverTime);
    }

    public static function fromPayload(string $bytes): self
    {
        [$sessionId, $offset] = self::decodeString($bytes, 0);

        if (strlen($bytes) < $offset + 8) {
            throw new ProtocolException('AUTH_OK message is missing its server time.');
        }

        return new self($sessionId, unpack('J', $bytes, $offset)[1]);
    }
}

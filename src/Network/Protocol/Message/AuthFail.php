<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** Authentication failed — PLAN.md §5.4. */
final readonly class AuthFail implements Message
{
    use WireStrings;

    public function __construct(
        public string $reason,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::AUTH_FAIL;
    }

    public function payload(): string
    {
        return self::encodeString($this->reason);
    }

    public static function fromPayload(string $bytes): self
    {
        [$reason] = self::decodeString($bytes, 0);

        return new self($reason);
    }
}

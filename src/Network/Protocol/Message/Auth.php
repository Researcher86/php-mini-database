<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/**
 * The client's login — PLAN.md §5.5: `$response` is
 * `HMAC(hash, nonce)`, never the password itself.
 */
final readonly class Auth implements Message
{
    use WireStrings;

    public function __construct(
        public string $username,
        public string $response,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::AUTH;
    }

    public function payload(): string
    {
        return self::encodeString($this->username) . self::encodeString($this->response);
    }

    public static function fromPayload(string $bytes): self
    {
        [$username, $offset] = self::decodeString($bytes, 0);
        [$response] = self::decodeString($bytes, $offset);

        return new self($username, $response);
    }
}

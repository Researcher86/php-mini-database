<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/**
 * The server's half of the handshake — PLAN.md §5.4.
 *
 * `$nonce` is what PLAN.md §5.4's own sequence names `salt`, renamed once
 * Milestone 13 gave the field an actual job: at `HELLO` time the server
 * does not yet know which user is connecting, so it has no per-user salt
 * to send — what it *can* send is a fresh, per-connection random value
 * for `Network\Auth\ScramChallenge`'s HMAC, which is a nonce, not a salt.
 * The per-user salt this project uses is never sent at all; it is derived
 * from the username alone (`Network\Auth\PasswordHash::saltFor()`), which
 * both sides can compute without a round trip. See DECISIONS.md.
 */
final readonly class HelloAck implements Message
{
    use WireStrings;

    /** @param list<string> $capabilities */
    public function __construct(
        public string $serverVersion,
        public string $authMethod,
        public string $nonce,
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
            . self::encodeString($this->nonce)
            . self::encodeStringList($this->capabilities);
    }

    public static function fromPayload(string $bytes): self
    {
        [$serverVersion, $offset] = self::decodeString($bytes, 0);
        [$authMethod, $offset] = self::decodeString($bytes, $offset);
        [$nonce, $offset] = self::decodeString($bytes, $offset);
        [$capabilities] = self::decodeStringList($bytes, $offset);

        return new self($serverVersion, $authMethod, $nonce, $capabilities);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

use PhpMiniDatabase\Exception\ProtocolException;

/**
 * The fixed-structure envelope every message travels in — PLAN.md §5.2:
 * a 4-byte magic, a version, a message type, a byte of flags, a 4-byte
 * payload length, then the payload itself. `Codec` is what turns this into
 * and out of a `Message`; this class only knows the envelope, not what any
 * payload means.
 *
 * `toBytes()`/`fromBytes()` both work on exactly one complete frame's
 * bytes — `fromBytes()` throws if given anything shorter or longer than
 * that. Reassembling a frame out of a TCP stream that may deliver it in
 * pieces, or several at once, is `FrameReader`'s job, not this class's.
 */
final readonly class Frame
{
    /** "MDB1" — the four bytes every frame starts with. */
    public const MAGIC = "\x4D\x44\x42\x31";

    public const CURRENT_VERSION = 1;

    /** The header's fixed size: magic(4) + version(2) + type(1) + flags(1) + length(4). */
    public const HEADER_SIZE = 12;

    /**
     * A payload larger than this is refused outright, in either direction —
     * the OOM protection PLAN.md §14 names as a risk, enforced at the one
     * place every frame passes through regardless of which message it
     * carries.
     */
    public const MAX_PAYLOAD_SIZE = 64 * 1024 * 1024;

    public function __construct(
        public MessageType $type,
        public string $payload = '',
        public int $flags = 0,
        public int $version = self::CURRENT_VERSION,
    ) {
        if (strlen($payload) > self::MAX_PAYLOAD_SIZE) {
            throw new ProtocolException(sprintf(
                'Frame payload of %d bytes exceeds the %d byte limit.',
                strlen($payload),
                self::MAX_PAYLOAD_SIZE,
            ));
        }
    }

    public function toBytes(): string
    {
        return self::MAGIC
            . pack('n', $this->version)
            . pack('C', $this->type->value)
            . pack('C', $this->flags)
            . pack('N', strlen($this->payload))
            . $this->payload;
    }

    /** @throws ProtocolException when $bytes is not exactly one well-formed frame */
    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) < self::HEADER_SIZE) {
            throw new ProtocolException('Frame is shorter than its header.');
        }

        if (substr($bytes, 0, 4) !== self::MAGIC) {
            throw new ProtocolException('Frame does not start with the "MDB1" magic bytes.');
        }

        $version = unpack('n', $bytes, 4)[1];
        $typeCode = unpack('C', $bytes, 6)[1];
        $flags = unpack('C', $bytes, 7)[1];
        $length = unpack('N', $bytes, 8)[1];

        if ($length > self::MAX_PAYLOAD_SIZE) {
            throw new ProtocolException(sprintf(
                'Frame declares a %d byte payload, over the %d byte limit.',
                $length,
                self::MAX_PAYLOAD_SIZE,
            ));
        }

        if (strlen($bytes) !== self::HEADER_SIZE + $length) {
            throw new ProtocolException(sprintf(
                'Frame declares a %d byte payload but %d byte(s) are actually present.',
                $length,
                strlen($bytes) - self::HEADER_SIZE,
            ));
        }

        $type = MessageType::tryFrom($typeCode)
            ?? throw new ProtocolException(sprintf('Unknown message type 0x%02X.', $typeCode));

        return new self($type, substr($bytes, self::HEADER_SIZE), $flags, $version);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Support\Binary;
use PhpMiniDatabase\Transaction\IsolationLevel;

/**
 * `BEGIN` over the wire, optionally naming an isolation level — the same
 * shape `Sql\Ast\BeginStatement` already has for `BEGIN` sent as plain SQL
 * text (Phase 8); this is the same information sent as a typed message
 * instead. `Transaction\IsolationLevel` is reused directly, matching that
 * precedent.
 */
final readonly class Begin implements Message
{
    public function __construct(
        public ?IsolationLevel $isolationLevel = null,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::BEGIN;
    }

    public function payload(): string
    {
        if ($this->isolationLevel === null) {
            return "\x00";
        }

        $code = match ($this->isolationLevel) {
            IsolationLevel::READ_COMMITTED => 0,
            IsolationLevel::REPEATABLE_READ => 1,
            IsolationLevel::SERIALIZABLE => 2,
        };

        return "\x01" . pack('C', $code);
    }

    public static function fromPayload(string $bytes): self
    {
        if ($bytes === '' || $bytes[0] === "\x00") {
            return new self();
        }

        if (strlen($bytes) < 2) {
            throw new ProtocolException('BEGIN message is missing its isolation level.');
        }

        $level = match (Binary::unpackInt('C', $bytes, 1)) {
            0 => IsolationLevel::READ_COMMITTED,
            1 => IsolationLevel::REPEATABLE_READ,
            2 => IsolationLevel::SERIALIZABLE,
            default => throw new ProtocolException('BEGIN message names an unknown isolation level.'),
        };

        return new self($level);
    }
}

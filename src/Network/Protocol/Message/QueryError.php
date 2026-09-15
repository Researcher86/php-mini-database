<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Network\Protocol\WireValue;

/**
 * A failed `Query`/`Execute` — PLAN.md §19's error shape: a code, a
 * human-readable message, and a small bag of named context (the table, the
 * constraint, the value involved — whatever `Execution\ConstraintEnforcer`
 * or the like already put in its own exception message, structured instead
 * of only prose). `$context`'s values are `WireValue`-encoded for the same
 * reason a `Query`'s parameters are: nothing here knows their `Schema\Type`.
 */
final readonly class QueryError implements Message
{
    use WireStrings;

    /** @param array<string, mixed> $context */
    public function __construct(
        public ErrorCode $code,
        public string $message,
        public array $context = [],
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::QUERY_ERROR;
    }

    public function payload(): string
    {
        $encoded = pack('C', $this->code->value) . self::encodeString($this->message) . pack('n', count($this->context));

        foreach ($this->context as $key => $value) {
            $encoded .= self::encodeString($key) . WireValue::encode($value);
        }

        return $encoded;
    }

    public static function fromPayload(string $bytes): self
    {
        if ($bytes === '') {
            throw new ProtocolException('QUERY_ERROR message is missing its error code.');
        }

        $code = ErrorCode::tryFrom(unpack('C', $bytes, 0)[1])
            ?? throw new ProtocolException('QUERY_ERROR message names an unknown error code.');

        [$message, $offset] = self::decodeString($bytes, 1);

        if (strlen($bytes) < $offset + 2) {
            throw new ProtocolException('QUERY_ERROR message is missing its context count.');
        }

        $count = unpack('n', $bytes, $offset)[1];
        $offset += 2;
        $context = [];

        for ($i = 0; $i < $count; $i++) {
            [$key, $offset] = self::decodeString($bytes, $offset);
            [$value, $offset] = WireValue::decode($bytes, $offset);
            $context[$key] = $value;
        }

        return new self($code, $message, $context);
    }
}

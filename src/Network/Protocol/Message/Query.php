<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Network\Protocol\WireValue;

/**
 * SQL text, plus whatever `?` placeholders it names bound to — each one a
 * self-describing `WireValue`, since a raw parameter has no column type
 * yet to encode it as (see `WireValue`'s own docblock).
 */
final readonly class Query implements Message
{
    use WireStrings;

    /** @param list<mixed> $parameters */
    public function __construct(
        public string $sql,
        public array $parameters = [],
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::QUERY;
    }

    public function payload(): string
    {
        $encoded = self::encodeString($this->sql) . pack('n', count($this->parameters));

        foreach ($this->parameters as $parameter) {
            $encoded .= WireValue::encode($parameter);
        }

        return $encoded;
    }

    public static function fromPayload(string $bytes): self
    {
        [$sql, $offset] = self::decodeString($bytes, 0);

        if (strlen($bytes) < $offset + 2) {
            throw new ProtocolException('QUERY message is missing its parameter count.');
        }

        $count = unpack('n', $bytes, $offset)[1];
        $offset += 2;
        $parameters = [];

        for ($i = 0; $i < $count; $i++) {
            [$value, $offset] = WireValue::decode($bytes, $offset);
            $parameters[] = $value;
        }

        return new self($sql, $parameters);
    }
}

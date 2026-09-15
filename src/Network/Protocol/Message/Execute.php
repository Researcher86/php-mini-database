<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Network\Protocol\WireValue;

/** Runs the prepared statement `$statementId` names, bound to `$parameters`. */
final readonly class Execute implements Message
{
    /** @param list<mixed> $parameters */
    public function __construct(
        public int $statementId,
        public array $parameters = [],
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::EXECUTE;
    }

    public function payload(): string
    {
        $encoded = pack('N', $this->statementId) . pack('n', count($this->parameters));

        foreach ($this->parameters as $parameter) {
            $encoded .= WireValue::encode($parameter);
        }

        return $encoded;
    }

    public static function fromPayload(string $bytes): self
    {
        if (strlen($bytes) < 6) {
            throw new ProtocolException('EXECUTE message is missing its statement id or parameter count.');
        }

        $statementId = unpack('N', $bytes, 0)[1];
        $count = unpack('n', $bytes, 4)[1];
        $offset = 6;
        $parameters = [];

        for ($i = 0; $i < $count; $i++) {
            [$value, $offset] = WireValue::decode($bytes, $offset);
            $parameters[] = $value;
        }

        return new self($statementId, $parameters);
    }
}

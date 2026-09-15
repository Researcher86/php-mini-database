<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/** A chunk of a bulk export. See `CopyIn`'s docblock. */
final readonly class CopyOut implements Message
{
    public function __construct(
        public string $data,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::COPY_OUT;
    }

    public function payload(): string
    {
        return $this->data;
    }

    public static function fromPayload(string $bytes): self
    {
        return new self($bytes);
    }
}

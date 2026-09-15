<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

/**
 * One message the protocol can carry — a `HELLO`, a `QUERY`, a `PING`, and
 * so on, one concrete class per `MessageType` in `Network\Protocol\Message\`.
 *
 * `type()` is what `Codec::encode()` puts in the `Frame` header; each
 * concrete class also carries its own `payload(): string` and a static
 * `fromPayload(string $bytes): self`, which — not being part of this
 * interface, since PHP cannot declare a static factory's return type as
 * "the implementing class" on an interface — `Codec::decode()` dispatches
 * to directly, by `MessageType`, the same way `Sql\Parser::statement()`
 * dispatches on a `TokenType` to build the right `Sql\Ast\Statement`.
 */
interface Message
{
    public function type(): MessageType;

    public function payload(): string;
}

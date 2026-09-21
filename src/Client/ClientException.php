<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Client;

use PhpMiniDatabase\Exception\DatabaseException;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use Throwable;

/**
 * Everything `Connection` throws — a failed connect or handshake, a
 * timed-out or malformed reply, or the server's own `QueryError` turned
 * into an exception instead of a value every caller would otherwise have
 * to remember to check for. One type to catch at the edge of a client
 * program, the same reasoning `Exception\DatabaseException` already gives
 * the rest of this project (see its own docblock).
 *
 * `$errorCode` is named that, not `$code`, to avoid colliding with
 * `Exception::$code` (untyped, `int`, inherited from `RuntimeException`) —
 * a second, incompatible declaration of the same property name would be a
 * fatal error. It is only ever set from a `QueryError`'s own
 * `Network\Protocol\ErrorCode`, or `AUTH_FAILED` for a failed handshake;
 * `null` for anything this project's wire protocol never labeled (a
 * connect failure, a timeout, a malformed frame).
 */
final class ClientException extends DatabaseException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly ?ErrorCode $errorCode = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function fromQueryError(QueryError $error): self
    {
        return new self($error->message, $error->code, $error->context);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * The bytes on the wire do not make a valid frame or message: a bad magic
 * number, an unsupported protocol version, a payload that does not match
 * what its message type expects. Distinct from `QUERY_ERROR` (a `Message`
 * in its own right, reporting that a *query* failed) — this is thrown
 * locally, before a `Message` could even be produced to report anything.
 */
final class ProtocolException extends DatabaseException
{
}

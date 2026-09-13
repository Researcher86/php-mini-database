<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * Raised when a value does not fit a column type: wrong PHP kind, value out of
 * range, or a string that a numeric/date type cannot parse. Carries a human
 * readable reason so the caller can echo it straight back to the user.
 */
final class TypeException extends DatabaseException
{
}

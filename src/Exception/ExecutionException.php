<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * Raised while running a parsed statement: an unknown table, column or
 * function referenced in it, a value that does not fit an operation
 * (division by zero, the wrong number of function arguments), or a
 * statement shape this phase of the executor does not implement yet
 * (a JOIN, a subquery, GROUP BY) — always with a clear, specific reason
 * rather than a silent wrong answer.
 */
final class ExecutionException extends DatabaseException
{
}

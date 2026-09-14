<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * Raised when a *value* breaks a rule the schema declares: a NULL in a NOT
 * NULL column, a duplicate in a UNIQUE one, a foreign key with no matching
 * parent row, a CHECK that evaluates false.
 *
 * The schema itself is fine in all of these — it is the row that is not — so
 * the caller's response is to reject the statement and report which
 * constraint refused it, not to conclude the database is damaged.
 */
final class ConstraintViolationException extends DatabaseException
{
}

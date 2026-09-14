<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * Raised when a schema does not describe something coherent, or when a query
 * names part of one that does not exist: an unknown table or column, a
 * primary key over a column the table does not have, two columns with the
 * same name, a malformed identifier.
 *
 * It is a description problem, not a data problem — a row that violates
 * UNIQUE raises ConstraintViolationException instead.
 */
final class SchemaException extends DatabaseException
{
}

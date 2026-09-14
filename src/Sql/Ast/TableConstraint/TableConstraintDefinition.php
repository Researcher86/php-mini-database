<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\TableConstraint;

/**
 * A constraint declared at the table level inside `CREATE TABLE`, rather
 * than inline on one column: `PRIMARY KEY (a, b)`, `UNIQUE (email)`,
 * `FOREIGN KEY (user_id) REFERENCES users (id)`, `CHECK (age >= 0)` —
 * optionally introduced by `CONSTRAINT name`.
 *
 * These carry the same information as a column's inline shorthand (a
 * column-level `PRIMARY KEY` is exactly a one-column `PrimaryKeyDefinition`)
 * and whatever builds a `Schema\Table` from the parsed statement treats both
 * the same way once it has collected them.
 */
interface TableConstraintDefinition
{
}

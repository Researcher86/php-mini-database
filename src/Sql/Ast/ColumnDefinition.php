<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

use PhpMiniDatabase\Sql\Ast\TableConstraint\ForeignKeyDefinition;

/**
 * One column of a `CREATE TABLE` (or the column a `ADD COLUMN` introduces):
 * its name, its type exactly as written ("VARCHAR(255)", "DECIMAL(10,2)"),
 * and whichever inline modifiers followed it.
 *
 * The type is kept as a string, not resolved to a `Schema\Type` here —
 * that resolution is `TypeFactory::fromName()`'s job, and belongs to
 * whatever builds a `Schema\Table` from this statement, not to the parser.
 *
 * `$default` and `$check` are expressions rather than evaluated values,
 * because a default can be `CURRENT_TIMESTAMP` (a function, evaluated per
 * row) and a check can reference other columns — neither is decidable from
 * the column definition alone.
 */
final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $notNull = false,
        public bool $primaryKey = false,
        public bool $unique = false,
        public ?Expression $default = null,
        public ?Expression $check = null,
        public ?ForeignKeyDefinition $foreignKey = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\TableConstraint;

use PhpMiniDatabase\Schema\Constraint\ReferentialAction;

/**
 * `[CONSTRAINT name] FOREIGN KEY (columns) REFERENCES table (columns)
 * [ON DELETE action] [ON UPDATE action]`, and the inline column-level
 * shorthand `col TYPE REFERENCES table (column)`, which the parser expands
 * into one of these with `$columns` set to the one column it was written on.
 *
 * `ReferentialAction` is reused directly from `Schema\Constraint\` rather
 * than the parser inventing its own copy of the same four cases — see
 * DECISIONS.md.
 */
final readonly class ForeignKeyDefinition implements TableConstraintDefinition
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ReferentialAction $onDelete = ReferentialAction::NO_ACTION,
        public ReferentialAction $onUpdate = ReferentialAction::NO_ACTION,
        public ?string $name = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\TableConstraint;

final readonly class PrimaryKeyDefinition implements TableConstraintDefinition
{
    /** @param list<string> $columns */
    public function __construct(
        public array $columns,
    ) {
    }
}

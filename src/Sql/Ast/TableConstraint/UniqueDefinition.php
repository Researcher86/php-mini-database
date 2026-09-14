<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\TableConstraint;

final readonly class UniqueDefinition implements TableConstraintDefinition
{
    /** @param list<string> $columns */
    public function __construct(
        public array $columns,
        public ?string $name = null,
    ) {
    }
}

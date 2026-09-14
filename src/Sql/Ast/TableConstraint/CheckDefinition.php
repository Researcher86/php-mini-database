<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\TableConstraint;

use PhpMiniDatabase\Sql\Ast\Expression;

final readonly class CheckDefinition implements TableConstraintDefinition
{
    public function __construct(
        public Expression $expression,
        public ?string $name = null,
    ) {
    }
}

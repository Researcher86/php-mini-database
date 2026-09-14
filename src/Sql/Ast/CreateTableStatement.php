<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

use PhpMiniDatabase\Sql\Ast\TableConstraint\TableConstraintDefinition;

final readonly class CreateTableStatement implements Statement
{
    /**
     * @param list<ColumnDefinition>            $columns
     * @param list<TableConstraintDefinition>   $constraints
     */
    public function __construct(
        public string $table,
        public array $columns,
        public array $constraints = [],
        public bool $ifNotExists = false,
    ) {
    }
}

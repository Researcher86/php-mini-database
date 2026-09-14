<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\AlterAction;

use PhpMiniDatabase\Sql\Ast\ColumnDefinition;

final readonly class AddColumn implements AlterAction
{
    public function __construct(
        public ColumnDefinition $column,
    ) {
    }
}

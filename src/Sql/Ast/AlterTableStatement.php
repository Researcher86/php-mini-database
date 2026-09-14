<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

use PhpMiniDatabase\Sql\Ast\AlterAction\AlterAction;

final readonly class AlterTableStatement implements Statement
{
    public function __construct(
        public string $table,
        public AlterAction $action,
    ) {
    }
}

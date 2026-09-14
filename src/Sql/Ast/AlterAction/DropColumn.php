<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\AlterAction;

final readonly class DropColumn implements AlterAction
{
    public function __construct(
        public string $column,
    ) {
    }
}

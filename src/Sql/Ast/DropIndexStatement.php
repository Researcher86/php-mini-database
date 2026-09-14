<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

final readonly class DropIndexStatement implements Statement
{
    public function __construct(
        public string $name,
        public string $table,
    ) {
    }
}

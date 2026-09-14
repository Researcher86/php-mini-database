<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

final readonly class DropTableStatement implements Statement
{
    public function __construct(
        public string $table,
        public bool $ifExists = false,
    ) {
    }
}

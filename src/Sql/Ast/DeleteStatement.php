<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

final readonly class DeleteStatement implements Statement
{
    public function __construct(
        public string $table,
        public ?Expression $where = null,
    ) {
    }
}

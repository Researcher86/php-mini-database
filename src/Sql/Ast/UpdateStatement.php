<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

final readonly class UpdateStatement implements Statement
{
    /** @param list<Assignment> $assignments */
    public function __construct(
        public string $table,
        public array $assignments,
        public ?Expression $where = null,
    ) {
    }
}

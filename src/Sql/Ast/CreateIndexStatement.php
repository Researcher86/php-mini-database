<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

final readonly class CreateIndexStatement implements Statement
{
    /** @param list<string> $columns */
    public function __construct(
        public string $name,
        public string $table,
        public array $columns,
        public bool $unique = false,
    ) {
    }
}

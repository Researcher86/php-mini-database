<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/** `SAVEPOINT name`. */
final readonly class SavepointStatement implements Statement
{
    public function __construct(
        public string $name,
    ) {
    }
}

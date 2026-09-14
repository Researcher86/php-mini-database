<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/** `RELEASE SAVEPOINT name`. */
final readonly class ReleaseSavepointStatement implements Statement
{
    public function __construct(
        public string $name,
    ) {
    }
}

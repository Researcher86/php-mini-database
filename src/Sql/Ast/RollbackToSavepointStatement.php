<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/** `ROLLBACK TO SAVEPOINT name` (`SAVEPOINT` itself is optional, as in most SQL dialects). */
final readonly class RollbackToSavepointStatement implements Statement
{
    public function __construct(
        public string $name,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\From;

/** A table named directly in FROM: `users` or `users AS u`. */
final readonly class TableReference implements FromItem
{
    public function __construct(
        public string $table,
        public ?string $alias = null,
    ) {
    }

    /** The name a column qualifier or a join condition would use for this. */
    public function referenceName(): string
    {
        return $this->alias ?? $this->table;
    }
}

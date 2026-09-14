<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * `*` or `table.*` in a select list — "every column", optionally scoped to
 * one table of the FROM clause. Not valid as a general expression (it
 * cannot appear in a WHERE clause); the parser only produces it where the
 * grammar allows it.
 */
final readonly class Star implements Expression
{
    public function __construct(
        public ?string $qualifier = null,
    ) {
    }
}

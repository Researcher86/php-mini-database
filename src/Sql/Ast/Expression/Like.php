<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * `expr [NOT] LIKE pattern` — `%` and `_` are wildcards, interpreted by the
 * evaluator (Phase 5), not the parser: the pattern is just an expression
 * here, most often a string `Literal`.
 */
final readonly class Like implements Expression
{
    public function __construct(
        public Expression $subject,
        public Expression $pattern,
        public bool $negated = false,
    ) {
    }
}

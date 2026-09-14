<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/** `expr [NOT] IN (a, b, c)` against a literal list of expressions. */
final readonly class InList implements Expression
{
    /** @param list<Expression> $values */
    public function __construct(
        public Expression $subject,
        public array $values,
        public bool $negated = false,
    ) {
    }
}

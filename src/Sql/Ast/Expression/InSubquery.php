<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\SelectStatement;

/** `expr [NOT] IN (SELECT ...)`. */
final readonly class InSubquery implements Expression
{
    public function __construct(
        public Expression $subject,
        public SelectStatement $query,
        public bool $negated = false,
    ) {
    }
}

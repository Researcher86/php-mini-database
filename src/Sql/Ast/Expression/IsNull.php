<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/** `expr IS [NOT] NULL`. */
final readonly class IsNull implements Expression
{
    public function __construct(
        public Expression $subject,
        public bool $negated = false,
    ) {
    }
}

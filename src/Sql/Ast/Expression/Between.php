<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/** `expr [NOT] BETWEEN low AND high`. */
final readonly class Between implements Expression
{
    public function __construct(
        public Expression $subject,
        public Expression $low,
        public Expression $high,
        public bool $negated = false,
    ) {
    }
}

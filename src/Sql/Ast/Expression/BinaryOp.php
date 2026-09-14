<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/** Two expressions joined by an operator: `age + 1`, `a.id = b.user_id`. */
final readonly class BinaryOp implements Expression
{
    public function __construct(
        public Expression $left,
        public BinaryOperator $operator,
        public Expression $right,
    ) {
    }
}

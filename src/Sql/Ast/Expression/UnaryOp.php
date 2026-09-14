<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/** An operator applied to a single expression: `-age`, `NOT active`. */
final readonly class UnaryOp implements Expression
{
    public function __construct(
        public UnaryOperator $operator,
        public Expression $operand,
    ) {
    }
}

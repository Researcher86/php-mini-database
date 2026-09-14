<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

enum UnaryOperator
{
    /** Arithmetic negation: `-age`. */
    case NEGATE;

    /** Logical negation: `NOT active`. */
    case NOT;
}

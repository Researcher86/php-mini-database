<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/** One `column = expression` term of an `UPDATE ... SET` clause. */
final readonly class Assignment
{
    public function __construct(
        public string $column,
        public Expression $value,
    ) {
    }
}

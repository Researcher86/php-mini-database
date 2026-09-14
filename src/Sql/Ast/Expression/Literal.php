<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * A value written directly into the SQL text: a number, a string, `TRUE` /
 * `FALSE`, or `NULL`.
 *
 * The parser resolves a number token to `int` or `float` here (a `NUMBER`
 * token with no `.` becomes an int) so that everything downstream — the
 * evaluator, a column default — works with a native PHP value already,
 * rather than re-parsing digit text at every use.
 */
final readonly class Literal implements Expression
{
    public function __construct(
        public int|float|string|bool|null $value,
    ) {
    }
}

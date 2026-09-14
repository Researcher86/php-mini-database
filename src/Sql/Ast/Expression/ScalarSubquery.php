<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\SelectStatement;

/**
 * A `SELECT` used where a single value is expected: `WHERE age > (SELECT
 * AVG(age) FROM users)`. Whether it actually returns one row and one column
 * is checked when it runs, not by the parser.
 */
final readonly class ScalarSubquery implements Expression
{
    public function __construct(
        public SelectStatement $query,
    ) {
    }
}

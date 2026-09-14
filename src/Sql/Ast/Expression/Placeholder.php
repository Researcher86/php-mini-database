<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * A `?` parameter placeholder, for a prepared statement (Phase 14). `$index`
 * is its 0-based position among the placeholders in its statement, assigned
 * left to right by the parser so a caller can bind parameters by position
 * without recounting `?`s itself.
 */
final readonly class Placeholder implements Expression
{
    public function __construct(
        public int $index,
    ) {
    }
}

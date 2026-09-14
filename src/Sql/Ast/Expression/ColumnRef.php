<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * A reference to a column, optionally qualified by a table or alias:
 * `email` or `u.email`.
 *
 * The qualifier is kept exactly as written and is not resolved to an actual
 * table here — that needs the FROM clause's aliases in scope, which belongs
 * to the planner (Phase 9), not the parser.
 */
final readonly class ColumnRef implements Expression
{
    public function __construct(
        public string $column,
        public ?string $qualifier = null,
    ) {
    }
}

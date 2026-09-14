<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * A named function applied to its arguments: `UPPER(email)`,
 * `COUNT(*)`, `COUNT(DISTINCT status)`, `CURRENT_TIMESTAMP` (no
 * parentheses, no arguments).
 *
 * The parser does not know the set of functions that exist — it accepts any
 * identifier followed by `(...)` (or, for a handful of zero-argument
 * date/time keywords, none at all) and leaves recognising the name to the
 * evaluator (Phase 5), the same way it leaves recognising a table name to
 * the executor. `$arguments` holds `[Star::class]` for the bare `*` inside
 * `COUNT(*)`, since that is the one place `*` is a legal argument rather
 * than a select-list wildcard.
 */
final readonly class FunctionCall implements Expression
{
    /** @param list<Expression> $arguments */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public bool $distinct = false,
    ) {
    }
}

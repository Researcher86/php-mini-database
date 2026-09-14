<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * One entry in a select list: an expression and the alias it was given, if
 * any (`price * qty AS total`). `Expression\Star` is a legal `$expression`
 * here — `SELECT *` and `SELECT u.*` are select lists of one `Star` each —
 * even though `Star` cannot appear anywhere else an `Expression` is
 * expected.
 */
final readonly class SelectItem
{
    public function __construct(
        public Expression $expression,
        public ?string $alias = null,
    ) {
    }
}

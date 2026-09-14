<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

use PhpMiniDatabase\Transaction\IsolationLevel;

/**
 * `BEGIN` / `START TRANSACTION`, optionally naming an isolation level
 * (`BEGIN ISOLATION LEVEL SERIALIZABLE`). `IsolationLevel` is reused
 * directly from `Transaction\` here the same way `Schema\Constraint\ReferentialAction`
 * is reused in `TableConstraint\ForeignKeyDefinition` — see DECISIONS.md.
 */
final readonly class BeginStatement implements Statement
{
    public function __construct(
        public ?IsolationLevel $isolationLevel = null,
    ) {
    }
}

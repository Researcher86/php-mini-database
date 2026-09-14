<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * Anything that evaluates to a value: a literal, a column reference, an
 * operator applied to other expressions, a function call, a subquery. This
 * is the tree `Execution\Expression\Evaluator` (Phase 5) walks — the same
 * one the parser built, per PLAN.md §3.4's "immutable AST and plans", not a
 * second tree re-derived from it.
 */
interface Expression extends Node
{
}

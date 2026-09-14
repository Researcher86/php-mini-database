<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * The common ancestor of every AST node. It carries no members of its own —
 * `Statement` and `Expression` are the two shapes anything that walks a tree
 * generically (a future EXPLAIN printer, a plan builder) needs to tell
 * apart, and this is the type both extend so such a walker has one thing to
 * accept rather than two.
 */
interface Node
{
}

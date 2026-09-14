<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\From;

use PhpMiniDatabase\Sql\Ast\Node;

/**
 * One thing a query reads rows from: a named table, a derived table (a
 * subquery given an alias), or a join of two further items. A `SELECT`'s
 * FROM clause is one `FromItem` — joins nest, so `a JOIN b JOIN c ON ...` is
 * a `Join` whose left side is itself a `Join`.
 */
interface FromItem extends Node
{
}

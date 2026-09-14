<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\From;

use PhpMiniDatabase\Sql\Ast\SelectStatement;

/**
 * A subquery standing in for a table: `(SELECT ... ) AS t`. The alias is
 * required — standard SQL does not allow an unaliased derived table, since
 * nothing could then refer to its columns.
 */
final readonly class DerivedTable implements FromItem
{
    public function __construct(
        public SelectStatement $query,
        public string $alias,
    ) {
    }
}

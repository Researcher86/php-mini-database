<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\From;

use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * Two FROM items joined on a condition. Left-associative chaining
 * (`a JOIN b JOIN c`) nests as `Join(Join(a, b), c)`, matching how the
 * parser reads them left to right.
 */
final readonly class Join implements FromItem
{
    public function __construct(
        public FromItem $left,
        public JoinType $type,
        public FromItem $right,
        public Expression $on,
    ) {
    }
}

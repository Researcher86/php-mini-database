<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/** One `ORDER BY` term: an expression and which way to sort by it. */
final readonly class OrderByItem
{
    public function __construct(
        public Expression $expression,
        public OrderDirection $direction = OrderDirection::ASC,
    ) {
    }
}

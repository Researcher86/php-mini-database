<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

use PhpMiniDatabase\Sql\Ast\From\FromItem;

final readonly class SelectStatement implements Statement
{
    /**
     * @param list<SelectItem>  $columns
     * @param list<Expression>  $groupBy
     * @param list<OrderByItem> $orderBy
     */
    public function __construct(
        public array $columns,
        public ?FromItem $from = null,
        public ?Expression $where = null,
        public bool $distinct = false,
        public array $groupBy = [],
        public ?Expression $having = null,
        public array $orderBy = [],
        public ?int $limit = null,
        public ?int $offset = null,
    ) {
    }
}

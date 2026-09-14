<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * `INSERT INTO table [(columns)] VALUES (...), (...), ...`.
 *
 * `$columns` is `null` when the statement did not name any — "insert into
 * every column, in the table's own order" — which is a different thing from
 * naming an empty list, so absence has to survive as `null` rather than
 * `[]`.
 */
final readonly class InsertStatement implements Statement
{
    /**
     * @param ?list<string>          $columns
     * @param list<list<Expression>> $rows
     */
    public function __construct(
        public string $table,
        public ?array $columns,
        public array $rows,
    ) {
    }
}

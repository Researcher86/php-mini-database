<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use PhpMiniDatabase\Schema\Row;

/**
 * What `SELECT` produces: the result's column names — known up front, from
 * the select list, whether or not any row is ever pulled — and the rows
 * themselves as a single-pass, streamed sequence.
 *
 * `$rows` is `iterable`, not `array`: it is usually the live generator
 * chain from `Executor::executeSelect()`, and materializing it here would
 * undo the whole point of building the operators as generators. A caller
 * that needs the rows more than once collects them itself
 * (`iterator_to_array($result->rows, false)`).
 */
final readonly class QueryResult
{
    /**
     * @param list<string>   $columns
     * @param iterable<Row>  $rows
     */
    public function __construct(
        public array $columns,
        public iterable $rows,
    ) {
    }
}

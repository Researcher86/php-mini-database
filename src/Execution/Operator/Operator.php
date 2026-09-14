<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use IteratorAggregate;
use PhpMiniDatabase\Schema\Row;

/**
 * A step in the read path a `SELECT` is built from: something that yields
 * rows, usually by pulling from another `Operator` underneath it. Being an
 * `IteratorAggregate` is what lets one compose with `foreach` directly and
 * lets a plain generator satisfy the interface with no wrapper class.
 *
 * This is a *read-only* pipeline. `INSERT`, `UPDATE` and `DELETE` do not
 * flow through it — see DECISIONS.md for why mutation is kept out.
 *
 * @extends IteratorAggregate<mixed, Row>
 */
interface Operator extends IteratorAggregate
{
    /** @return Generator<mixed, Row> */
    public function getIterator(): Generator;
}

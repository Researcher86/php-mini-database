<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Support;

use Generator;
use PhpMiniDatabase\Execution\Operator\Operator;
use PhpMiniDatabase\Schema\Row;

/**
 * A fixed list of rows standing in for a real source operator — Filter,
 * Limit, Sort and Project are tested against this rather than a real
 * SeqScan, since none of their own logic depends on where the rows came
 * from. Keys are plain sequential ints, not RecordId, which is exactly what
 * lets these tests use iterator_to_array() directly instead of foreach.
 */
final readonly class ListOperator implements Operator
{
    /** @param list<Row> $rows */
    public function __construct(private array $rows)
    {
    }

    public function getIterator(): Generator
    {
        yield from $this->rows;
    }
}

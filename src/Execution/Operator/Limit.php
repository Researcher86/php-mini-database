<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;

/**
 * Skips `$offset` rows, then yields at most `$limit`, stopping the source
 * from producing anything further once satisfied — a plain `SELECT * FROM
 * huge_table LIMIT 1` never has to read past its first row.
 */
final readonly class Limit implements Operator
{
    public function __construct(
        private Operator $source,
        private ?int $limit = null,
        private int $offset = 0,
    ) {
    }

    public function getIterator(): Generator
    {
        if ($this->limit === 0) {
            return;
        }

        $skipped = 0;
        $taken = 0;

        foreach ($this->source as $id => $row) {
            if ($skipped < $this->offset) {
                $skipped++;
                continue;
            }

            yield $id => $row;
            $taken++;

            if ($this->limit !== null && $taken >= $this->limit) {
                return;
            }
        }
    }
}

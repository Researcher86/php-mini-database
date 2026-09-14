<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Schema\Row;

/**
 * An `INNER JOIN` restricted to one shape: `$on` is exactly
 * `left.key = right.key`. Rather than testing every left row against every
 * right row (`NestedLoopJoin`'s cost, quadratic in the two sides' sizes),
 * the right side is read once into a hash table keyed by its join column,
 * and each left row does one lookup — linear in the combined input size.
 *
 * `Executor` is the one that recognizes this shape and builds a `HashJoin`
 * instead of a `NestedLoopJoin`; this class does not check that `$on` was
 * really an equality on these two columns, it trusts the caller. `NULL`
 * never joins to anything, matching SQL's own equality semantics (`NULL =
 * NULL` is never true) — a row on either side with a `NULL` join key is
 * skipped rather than hashed or probed.
 *
 * Both inputs must already yield qualified rows, the same as
 * `NestedLoopJoin` — see `Qualify`.
 */
final readonly class HashJoin implements Operator
{
    public function __construct(
        private Operator $left,
        private Operator $right,
        private string $leftKey,
        private string $rightKey,
    ) {
    }

    public function getIterator(): Generator
    {
        /** @var array<string, list<Row>> $buckets */
        $buckets = [];

        foreach ($this->right as $rightRow) {
            $key = $rightRow->get($this->rightKey);

            if ($key !== null) {
                $buckets[$this->bucket($key)][] = $rightRow;
            }
        }

        foreach ($this->left as $leftRow) {
            $key = $leftRow->get($this->leftKey);

            if ($key === null) {
                continue;
            }

            foreach ($buckets[$this->bucket($key)] ?? [] as $rightRow) {
                // The bucket is keyed by a string form of the value, which
                // can collide across genuinely different values (1 and
                // "1"); re-comparing the real values before accepting a
                // match is what keeps a bucket hit from becoming a false
                // join.
                if (($key <=> $rightRow->get($this->rightKey)) === 0) {
                    yield new Row([...$leftRow->toArray(), ...$rightRow->toArray()]);
                }
            }
        }
    }

    private function bucket(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : serialize($value);
    }
}

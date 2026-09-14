<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Generator;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\QualifiedRowContext;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;

/**
 * `INNER`/`LEFT` join by brute force: every left row against every right
 * row, keeping the ones `$on` accepts. The general case — works for any
 * `$on` expression, not just an equality — and therefore the fallback
 * whenever `Executor` cannot use the faster `HashJoin` (see its own
 * docblock and DECISIONS.md).
 *
 * Both inputs must already yield qualified rows (`"ref.column"` keys, from
 * `Qualify` or a nested join) — this class only merges and matches, it
 * never qualifies anything itself.
 *
 * A `RIGHT JOIN` is not a third case here: `Executor` builds one by
 * swapping which side is "left" and which is "right" and asking for
 * `LEFT` — the `ON` expression does not care which physical side a value
 * came from, only what it is, so the swap changes nothing this class needs
 * to know about.
 *
 * The right side is read once and kept in memory — the nested loop scans
 * it fully for every left row, so materializing it avoids re-running its
 * whole source (which could be an index scan, a filter, another join)
 * once per left row.
 */
final readonly class NestedLoopJoin implements Operator
{
    /**
     * @param list<mixed>  $parameters
     * @param list<string> $rightColumnKeys every qualified column key the
     *                                      right side can produce, used to
     *                                      null-pad an unmatched left row
     *                                      under `LEFT`
     */
    public function __construct(
        private Operator $left,
        private Operator $right,
        private bool $isLeftJoin,
        private Expression $on,
        private Evaluator $evaluator,
        private array $parameters,
        private array $rightColumnKeys = [],
    ) {
    }

    public function getIterator(): Generator
    {
        $rightRows = iterator_to_array($this->right, false);

        foreach ($this->left as $leftRow) {
            $matched = false;

            foreach ($rightRows as $rightRow) {
                $combined = new Row([...$leftRow->toArray(), ...$rightRow->toArray()]);
                $context = new QualifiedRowContext($combined, $this->parameters);

                if ($this->evaluator->isTrue($this->evaluator->evaluate($this->on, $context))) {
                    yield $combined;
                    $matched = true;
                }
            }

            if (!$matched && $this->isLeftJoin) {
                yield new Row([...$leftRow->toArray(), ...array_fill_keys($this->rightColumnKeys, null)]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Closure;
use Generator;
use PhpMiniDatabase\Execution\Expression\EvaluationContext;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\OrderByItem;
use PhpMiniDatabase\Sql\Ast\OrderDirection;

/**
 * Orders its source by one or more expressions. Unlike every other operator
 * here, this one cannot stream: the last row could sort first, so the whole
 * source has to be read before the first one can be yielded. That is an
 * inherent property of sorting, not a shortcut this implementation took —
 * every database materializes (or spills to disk) for a sort with no index
 * to satisfy it.
 *
 * `NULL` sorts as the lowest possible value in every column, so it comes
 * first under `ASC` and last under `DESC` — the same convention MySQL uses,
 * chosen so that reversing the direction is exactly reversing the order,
 * with no special case for where NULLs land.
 *
 * `usort()` is stable since PHP 8.0, so rows that compare equal on every
 * `ORDER BY` key keep the order they arrived in rather than being shuffled.
 *
 * `$contextFor` builds the `EvaluationContext` an `ORDER BY` expression is
 * evaluated against — see `Filter`'s docblock for why this is a closure
 * rather than a fixed table/alias pair.
 */
final readonly class Sort implements Operator
{
    /**
     * @param list<OrderByItem>              $orderBy
     * @param Closure(Row): EvaluationContext $contextFor
     */
    public function __construct(
        private Operator $source,
        private array $orderBy,
        private Evaluator $evaluator,
        private Closure $contextFor,
    ) {
    }

    public function getIterator(): Generator
    {
        $entries = [];

        foreach ($this->source as $id => $row) {
            $context = ($this->contextFor)($row);
            $keys = array_map(
                fn (OrderByItem $item): mixed => $this->evaluator->evaluate($item->expression, $context),
                $this->orderBy,
            );

            $entries[] = ['id' => $id, 'row' => $row, 'keys' => $keys];
        }

        usort($entries, function (array $a, array $b): int {
            foreach ($this->orderBy as $i => $item) {
                $comparison = $this->compare($a['keys'][$i], $b['keys'][$i]);

                if ($item->direction === OrderDirection::DESC) {
                    $comparison = -$comparison;
                }

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        foreach ($entries as $entry) {
            yield $entry['id'] => $entry['row'];
        }
    }

    private function compare(mixed $a, mixed $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => -1,
            $b === null => 1,
            default => $a <=> $b,
        };
    }
}

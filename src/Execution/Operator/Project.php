<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Closure;
use Generator;
use PhpMiniDatabase\Execution\Expression\EvaluationContext;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\SelectItem;

/**
 * Turns each source row into the result row a `SELECT` list describes:
 * evaluate every item's expression, keyed by its label.
 *
 * `SELECT *` is not this class's problem — by the time an `Operator` chain
 * reaches `Project`, `Executor` has already expanded a `Star` into one
 * `ColumnRef` per column of the table, so every `SelectItem` here names an
 * ordinary value-producing expression. `$contextFor` builds the
 * `EvaluationContext` each item's expression is evaluated against — see
 * `Filter`'s docblock for why this is a closure rather than a fixed
 * table/alias pair.
 */
final readonly class Project implements Operator
{
    /**
     * @param list<SelectItem>               $items
     * @param list<string>                   $labels  one per item, from self::label()
     * @param Closure(Row): EvaluationContext $contextFor
     */
    public function __construct(
        private Operator $source,
        private array $items,
        private array $labels,
        private Evaluator $evaluator,
        private Closure $contextFor,
    ) {
    }

    public function getIterator(): Generator
    {
        foreach ($this->source as $id => $row) {
            $context = ($this->contextFor)($row);
            $values = [];

            foreach ($this->items as $i => $item) {
                $values[$this->labels[$i]] = $this->evaluator->evaluate($item->expression, $context);
            }

            yield $id => new Row($values);
        }
    }

    /**
     * The result column name for one select item: its alias if it was
     * given one, otherwise a name derived from the expression it wraps — a
     * bare column keeps its own name, a function call is named after the
     * function, and anything else (an arithmetic expression, a literal)
     * falls back to its 1-based position, the same way a client with no
     * better name to show would number an unlabelled result column.
     */
    public static function label(SelectItem $item, int $position): string
    {
        if ($item->alias !== null) {
            return $item->alias;
        }

        return match (true) {
            $item->expression instanceof ColumnRef => $item->expression->column,
            $item->expression instanceof FunctionCall => $item->expression->name,
            default => sprintf('column%d', $position + 1),
        };
    }
}

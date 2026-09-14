<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Operator;

use Closure;
use Generator;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Expression\EvaluationContext;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\SelectItem;

/**
 * `GROUP BY` and its aggregate functions — `COUNT`, `SUM`, `AVG`, `MIN`,
 * `MAX` — collapsed into one operator, because they are really one
 * mechanism: every source row is bucketed by its `GROUP BY` key tuple,
 * then each select-list expression (and `HAVING`, if present) is evaluated
 * once per bucket rather than once per row. This is `Project`'s replacement
 * for a grouped query, not something that runs alongside it — its output
 * rows are already the final, labelled result.
 *
 * An aggregate function call anywhere in an expression — not only a bare
 * `COUNT(*)`, but also `COUNT(*) > 1` or `SUM(x) - SUM(y)` — is computed
 * over the bucket's rows and substituted as a `Literal` before the
 * *rewritten* expression is handed to the ordinary `Evaluator` against one
 * representative row of the bucket (its first). That representative row is
 * also what answers a non-aggregate reference, such as the `status` in
 * `SELECT status, COUNT(*) ... GROUP BY status` — every row in a bucket
 * shares its `GROUP BY` key values by construction, so any one of them
 * gives the right answer. This project does not check that every
 * non-aggregate expression is actually one of the `GROUP BY` keys the way
 * a stricter SQL implementation would; an expression that is neither an
 * aggregate nor a real group key still evaluates, against an arbitrary row
 * of the bucket, rather than being rejected.
 *
 * With no `GROUP BY` clause at all, the whole source is one implicit
 * bucket — including an *empty* one: `SELECT COUNT(*) FROM t WHERE false`
 * is one row with `COUNT(*) = 0`, standard SQL behaviour for an aggregate
 * over zero rows, not zero rows of output. A real `GROUP BY` over zero
 * source rows is the opposite: zero buckets, zero output rows.
 *
 * Like `Sort`, this cannot stream — every row has to be read to know which
 * bucket it belongs to before any bucket can be finished.
 */
final readonly class Aggregate implements Operator
{
    /** The recognized aggregate function names, shared with Executor's detection of an aggregate query. */
    public const AGGREGATE_FUNCTIONS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /**
     * @param list<Expression>                $groupBy
     * @param list<SelectItem>                $items
     * @param list<string>                    $labels
     * @param Closure(Row): EvaluationContext  $contextFor
     */
    public function __construct(
        private Operator $source,
        private array $groupBy,
        private array $items,
        private array $labels,
        private ?Expression $having,
        private Evaluator $evaluator,
        private Closure $contextFor,
    ) {
    }

    public function getIterator(): Generator
    {
        /** @var array<string, array{keyValues: list<mixed>, rows: list<Row>}> $buckets */
        $buckets = [];

        foreach ($this->source as $row) {
            $keyValues = array_map(
                fn (Expression $expression): mixed => $this->evaluator->evaluate($expression, ($this->contextFor)($row)),
                $this->groupBy,
            );

            $key = serialize($keyValues);
            $buckets[$key]['keyValues'] = $keyValues;
            $buckets[$key]['rows'][] = $row;
        }

        if ($buckets === [] && $this->groupBy === []) {
            $buckets[''] = ['keyValues' => [], 'rows' => []];
        }

        foreach ($buckets as $bucket) {
            $representative = $bucket['rows'][0] ?? null;
            $context = $representative !== null ? ($this->contextFor)($representative) : new RowContext();

            if ($this->having !== null) {
                $havingValue = $this->evaluator->evaluate($this->substituteAggregates($this->having, $bucket['rows']), $context);

                if (!$this->evaluator->isTrue($havingValue)) {
                    continue;
                }
            }

            $values = [];
            foreach ($this->items as $i => $item) {
                $rewritten = $this->substituteAggregates($item->expression, $bucket['rows']);
                $values[$this->labels[$i]] = $this->evaluator->evaluate($rewritten, $context);
            }

            yield new Row($values);
        }
    }

    /**
     * Walks $expression, replacing every aggregate function call it finds
     * with a Literal holding that aggregate's value over $rows, and
     * rebuilding everything else unchanged. What comes back has no
     * aggregate calls left in it, so the plain Evaluator can run it
     * against one row exactly as it would any other expression.
     *
     * @param list<Row> $rows
     */
    private function substituteAggregates(Expression $expression, array $rows): Expression
    {
        if ($expression instanceof FunctionCall && in_array(strtoupper($expression->name), self::AGGREGATE_FUNCTIONS, true)) {
            return new Literal($this->computeAggregate($expression, $rows));
        }

        if ($expression instanceof BinaryOp) {
            return new BinaryOp(
                $this->substituteAggregates($expression->left, $rows),
                $expression->operator,
                $this->substituteAggregates($expression->right, $rows),
            );
        }

        if ($expression instanceof UnaryOp) {
            return new UnaryOp($expression->operator, $this->substituteAggregates($expression->operand, $rows));
        }

        return $expression;
    }

    /** @param list<Row> $rows */
    private function computeAggregate(FunctionCall $call, array $rows): mixed
    {
        $name = strtoupper($call->name);

        if ($name === 'COUNT') {
            if ($call->arguments === [] || $call->arguments[0] instanceof Star) {
                return count($rows);
            }

            $values = $this->argumentValues($call->arguments[0], $rows);

            return $call->distinct ? count(array_unique($values, SORT_REGULAR)) : count($values);
        }

        if (count($call->arguments) !== 1 || $call->arguments[0] instanceof Star) {
            throw new ExecutionException(sprintf('%s() expects exactly one argument.', $name));
        }

        $values = $this->argumentValues($call->arguments[0], $rows);

        if ($values === []) {
            return null; // SUM/AVG/MIN/MAX over nothing is NULL, the same as any other aggregate over zero rows
        }

        return match ($name) {
            'SUM' => array_sum($values),
            'AVG' => array_sum($values) / count($values),
            'MIN' => array_reduce($values, fn (mixed $a, mixed $b): mixed => $a === null || ($b <=> $a) < 0 ? $b : $a),
            'MAX' => array_reduce($values, fn (mixed $a, mixed $b): mixed => $a === null || ($b <=> $a) > 0 ? $b : $a),
            default => throw new ExecutionException(sprintf('Unknown aggregate function "%s".', $call->name)),
        };
    }

    /**
     * @param list<Row> $rows
     *
     * @return list<mixed> the argument's value for every row, NULLs dropped
     */
    private function argumentValues(Expression $argument, array $rows): array
    {
        $values = array_map(
            fn (Row $row): mixed => $this->evaluator->evaluate($argument, ($this->contextFor)($row)),
            $rows,
        );

        return array_values(array_filter($values, static fn (mixed $value): bool => $value !== null));
    }
}

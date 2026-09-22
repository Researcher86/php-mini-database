<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Operator\Aggregate as AggregateOperator;
use PhpMiniDatabase\Execution\Operator\Project as ProjectOperator;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\From\FromItem;
use PhpMiniDatabase\Sql\Ast\From\Join as FromJoin;
use PhpMiniDatabase\Sql\Ast\From\TableReference;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter;
use PhpMiniDatabase\Sql\Planner\Plan\Join;
use PhpMiniDatabase\Sql\Planner\Plan\Limit;
use PhpMiniDatabase\Sql\Planner\Plan\Project;
use PhpMiniDatabase\Sql\Planner\Plan\Scan;
use PhpMiniDatabase\Sql\Planner\Plan\Sort;

/**
 * Turns a `SelectStatement` with a `FROM` clause into a `LogicalPlan` —
 * what to read and in what order to do the rest, before anything decides
 * *how* (`Sql\Optimizer\Optimizer`) or runs it (`Execution\Executor`).
 *
 * This is a direct extraction of what `Execution\Executor` built ad hoc for
 * a `SELECT` through Phase 8: the shape of the tree below is exactly
 * `executeSelectFromTable()`/`executeSelectFromJoin()`/`finishSelect()`'s
 * old pipeline order, just as data instead of as a sequence of `Operator`
 * constructions. Nothing here picks an access method or a join
 * algorithm — every `Scan` starts with `index: null` and every `Join`
 * with `hash: null`, exactly the defaults `Optimizer`'s rules exist to
 * fill in.
 *
 * A `SELECT` with no `FROM` at all never reaches this class:
 * `Execution\Executor::executeSelectWithoutFrom()` evaluates its select
 * list directly, since there is no table to plan a read against.
 */
final readonly class Planner
{
    public function __construct(
        private Database $database,
    ) {
    }

    public function plan(SelectStatement $statement): PlannedQuery
    {
        if ($statement->from instanceof TableReference) {
            return $this->planTable($statement, $statement->from);
        }

        if ($statement->from instanceof FromJoin) {
            return $this->planJoin($statement, $statement->from);
        }

        throw new ExecutionException('Derived tables are not supported yet.');
    }

    private function planTable(SelectStatement $statement, TableReference $reference): PlannedQuery
    {
        $table = $this->database->table($reference->table);
        $items = $this->expandStars($statement->columns, $table, $reference->referenceName());
        $labels = $this->labelsFor($items);

        $plan = $this->tail(new Scan($table, $reference->alias), $statement, $items, $labels);

        return new PlannedQuery($plan, $labels);
    }

    private function planJoin(SelectStatement $statement, FromJoin $from): PlannedQuery
    {
        foreach ($statement->columns as $item) {
            if ($item->expression instanceof Star) {
                throw new ExecutionException('SELECT * is not supported for JOIN queries yet; list the columns explicitly.');
            }
        }

        $labels = $this->labelsFor($statement->columns);
        $plan = $this->tail($this->planFrom($from), $statement, $statement->columns, $labels);

        return new PlannedQuery($plan, $labels);
    }

    private function planFrom(FromItem $from): LogicalPlan
    {
        if ($from instanceof TableReference) {
            return new Scan($this->database->table($from->table), $from->alias);
        }

        if ($from instanceof FromJoin) {
            return new Join($this->planFrom($from->left), $from->type, $this->planFrom($from->right), $from->on);
        }

        throw new ExecutionException('Derived tables are not supported yet.');
    }

    /**
     * The tail every `FROM` shape shares once it has a source plan and its
     * select list resolved: `WHERE`, then `GROUP BY`/`HAVING`/aggregates or
     * a plain projection, then `DISTINCT`, then `LIMIT`/`OFFSET`. See
     * `Execution\Executor::finishSelect()`'s docblock (Phase 7) for why
     * `ORDER BY` sits before the projection here and after the aggregate
     * there — the same reasoning applies unchanged, now expressed as which
     * node wraps which.
     *
     * @param list<SelectItem> $items
     * @param list<string>     $labels
     */
    private function tail(LogicalPlan $plan, SelectStatement $statement, array $items, array $labels): LogicalPlan
    {
        if ($statement->where !== null) {
            $plan = new Filter($plan, $statement->where);
        }

        if ($statement->groupBy !== [] || $statement->having !== null || $this->containsAggregate($items)) {
            $plan = new Aggregate($plan, $statement->groupBy, $items, $labels, $statement->having);

            if ($statement->orderBy !== []) {
                $plan = new Sort($plan, $statement->orderBy);
            }
        } else {
            if ($statement->orderBy !== []) {
                $plan = new Sort($plan, $statement->orderBy);
            }

            $plan = new Project($plan, $items, $labels);
        }

        if ($statement->distinct) {
            $plan = new Distinct($plan);
        }

        return new Limit($plan, $statement->limit, $statement->offset ?? 0);
    }

    /** @param list<SelectItem> $items */
    private function containsAggregate(array $items): bool
    {
        return array_any($items, fn (SelectItem $item): bool => $this->expressionContainsAggregate($item->expression));
    }

    private function expressionContainsAggregate(Expression $expression): bool
    {
        if ($expression instanceof FunctionCall) {
            return in_array(strtoupper($expression->name), AggregateOperator::AGGREGATE_FUNCTIONS, true);
        }

        if ($expression instanceof BinaryOp) {
            return $this->expressionContainsAggregate($expression->left) || $this->expressionContainsAggregate($expression->right);
        }

        if ($expression instanceof UnaryOp) {
            return $this->expressionContainsAggregate($expression->operand);
        }

        return false;
    }

    /**
     * Replaces a bare `*`/`t.*` with one `SelectItem` per column of the
     * table, in table order. Only used for a single-table `FROM`; a joined
     * query disallows `*` instead (see `planJoin()`).
     *
     * @param list<SelectItem> $items
     *
     * @return list<SelectItem>
     */
    private function expandStars(array $items, Table $table, string $reference): array
    {
        $expanded = [];

        foreach ($items as $item) {
            if (!$item->expression instanceof Star) {
                $expanded[] = $item;
                continue;
            }

            if ($item->expression->qualifier !== null && $item->expression->qualifier !== $reference) {
                throw new ExecutionException(sprintf('Unknown table or alias "%s".', $item->expression->qualifier));
            }

            foreach ($table->columnNames() as $column) {
                $expanded[] = new SelectItem(new ColumnRef($column));
            }
        }

        return $expanded;
    }

    /**
     * @param list<SelectItem> $items
     *
     * @return list<string>
     */
    private function labelsFor(array $items): array
    {
        return array_map(
            static fn (SelectItem $item, int $i): string => ProjectOperator::label($item, $i),
            $items,
            array_keys($items),
        );
    }
}

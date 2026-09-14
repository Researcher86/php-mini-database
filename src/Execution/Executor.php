<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use Closure;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Expression\EvaluationContext;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\QualifiedRowContext;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Execution\Operator\Aggregate;
use PhpMiniDatabase\Execution\Operator\Distinct;
use PhpMiniDatabase\Execution\Operator\Filter;
use PhpMiniDatabase\Execution\Operator\HashJoin;
use PhpMiniDatabase\Execution\Operator\IndexScan;
use PhpMiniDatabase\Execution\Operator\Limit;
use PhpMiniDatabase\Execution\Operator\LockRows;
use PhpMiniDatabase\Execution\Operator\NestedLoopJoin;
use PhpMiniDatabase\Execution\Operator\Operator;
use PhpMiniDatabase\Execution\Operator\Project;
use PhpMiniDatabase\Execution\Operator\Qualify;
use PhpMiniDatabase\Execution\Operator\SeqScan;
use PhpMiniDatabase\Execution\Operator\Sort;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Ast\AlterTableStatement;
use PhpMiniDatabase\Sql\Ast\BeginStatement;
use PhpMiniDatabase\Sql\Ast\CommitStatement;
use PhpMiniDatabase\Sql\Ast\CreateIndexStatement;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Ast\DeleteStatement;
use PhpMiniDatabase\Sql\Ast\DropIndexStatement;
use PhpMiniDatabase\Sql\Ast\DropTableStatement;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\From\FromItem;
use PhpMiniDatabase\Sql\Ast\From\Join;
use PhpMiniDatabase\Sql\Ast\From\JoinType;
use PhpMiniDatabase\Sql\Ast\From\TableReference;
use PhpMiniDatabase\Sql\Ast\InsertStatement;
use PhpMiniDatabase\Sql\Ast\ReleaseSavepointStatement;
use PhpMiniDatabase\Sql\Ast\RollbackStatement;
use PhpMiniDatabase\Sql\Ast\RollbackToSavepointStatement;
use PhpMiniDatabase\Sql\Ast\SavepointStatement;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Ast\Statement;
use PhpMiniDatabase\Sql\Ast\UpdateStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Storage\RecordId;
use PhpMiniDatabase\Transaction\IsolationLevel;
use PhpMiniDatabase\Transaction\LockManager;
use PhpMiniDatabase\Transaction\LockMode;
use PhpMiniDatabase\Transaction\TransactionManager;
use PhpMiniDatabase\Transaction\WalOperation;
use PhpMiniDatabase\Transaction\WalRecord;
use Throwable;

/**
 * Runs one parsed `Statement` against a `Database`.
 *
 * `execute()` returns whichever of the three shapes a statement produces:
 * a `QueryResult` for `SELECT`, the number of affected rows (`int`) for
 * `INSERT`/`UPDATE`/`DELETE`, or `null` for a DDL statement that only
 * changed the catalog. A caller that already knows which kind it sent can
 * narrow the result itself; `run()` is the convenience for a caller that
 * has SQL text and nothing more specific to do with the answer.
 *
 * `INSERT`/`UPDATE`/`DELETE` keep every single-column index in step via
 * `IndexMaintainer` — uniqueness is checked before anything is written, not
 * after (see its own docblock). `SELECT` reaches for an `IndexScan` instead
 * of a `SeqScan` when the `WHERE` clause (or the first conjunct of an `AND`
 * chain) is a plain `column <op> constant` comparison against a column with
 * a single-column index; a `JOIN` becomes a `HashJoin` when it is a plain
 * equality between one column from each side, and a `NestedLoopJoin`
 * otherwise. Both are small, fixed rules standing in for Milestone 9's
 * planner — see `selectSource()`/`equiJoinKeys()` and DECISIONS.md.
 *
 * A query against a `JOIN` evaluates `WHERE`/`ON`/the select list against
 * `QualifiedRowContext` (columns named `"ref.column"`) rather than the
 * plain `RowContext` a single table uses — and, for that reason, does not
 * support a bare `SELECT *`/`t.*` yet: expanding a star needs to enumerate
 * every table in the join, which `expandStars()` does not do. List columns
 * explicitly instead.
 *
 * What this phase does *not* do, deliberately, and reports clearly rather
 * than silently mishandling: derived tables and subqueries (need the
 * planner — Phase 9); `ALTER TABLE` (Phase 10); enforcing `FOREIGN KEY`
 * and `CHECK` on a write (Phase 10 — `NOT NULL` is enforced by
 * `Schema\Table` itself, and `UNIQUE`/`PRIMARY KEY` by `IndexMaintainer`,
 * since neither needs anything this layer does not already have). See
 * DECISIONS.md for the reasoning behind each.
 *
 * `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` (Phase 8) dispatch straight to
 * `TransactionManager`. Every `INSERT`/`UPDATE`/`DELETE` runs inside a
 * transaction either way: the caller's own, if one is open, or — via
 * `withTransaction()` — a new one opened and closed around that one
 * statement alone, which is what gives a multi-row `INSERT` statement-
 * level atomicity. `undo()` is the other half of that wiring: the
 * `Closure` `TransactionManager` calls to physically reverse one logged
 * change, resolving what would otherwise be a circular dependency between
 * the two classes (see `TransactionManager`'s own docblock). Row/table
 * locking is scoped to single-table statements only — a `JOIN`'s rows
 * lose their per-table `RecordId` once merged, so there is nothing to
 * attach a lock to (see DECISIONS.md).
 */
final readonly class Executor
{
    private IndexMaintainer $indexMaintainer;

    private LockManager $locks;

    private TransactionManager $transactions;

    public function __construct(
        private Database $database,
        private Evaluator $evaluator = new Evaluator(),
        private TableBuilder $tableBuilder = new TableBuilder(),
    ) {
        $this->indexMaintainer = new IndexMaintainer($database);
        $this->locks = $database->locks();
        $this->transactions = $database->transactions();
        $this->transactions->setUndoHandler($this->undo(...));
        $this->transactions->recover();
    }

    /** @param list<mixed> $parameters */
    public function run(string $sql, array $parameters = []): QueryResult|int|null
    {
        return $this->execute(Parser::parseOne($sql), $parameters);
    }

    /** @param list<mixed> $parameters */
    public function execute(Statement $statement, array $parameters = []): QueryResult|int|null
    {
        return match (true) {
            $statement instanceof SelectStatement => $this->executeSelect($statement, $parameters),
            $statement instanceof InsertStatement => $this->executeInsert($statement, $parameters),
            $statement instanceof UpdateStatement => $this->executeUpdate($statement, $parameters),
            $statement instanceof DeleteStatement => $this->executeDelete($statement, $parameters),
            $statement instanceof CreateTableStatement => $this->executeCreateTable($statement),
            $statement instanceof DropTableStatement => $this->executeDropTable($statement),
            $statement instanceof CreateIndexStatement => $this->executeCreateIndex($statement),
            $statement instanceof DropIndexStatement => $this->executeDropIndex($statement),
            $statement instanceof BeginStatement => $this->executeBegin($statement),
            $statement instanceof CommitStatement => $this->executeCommit(),
            $statement instanceof RollbackStatement => $this->executeRollback(),
            $statement instanceof SavepointStatement => $this->executeSavepoint($statement),
            $statement instanceof ReleaseSavepointStatement => $this->executeReleaseSavepoint($statement),
            $statement instanceof RollbackToSavepointStatement => $this->executeRollbackToSavepoint($statement),
            $statement instanceof AlterTableStatement => throw new ExecutionException(
                sprintf('%s is not supported yet.', $statement::class),
            ),
            default => throw new ExecutionException(sprintf('Unknown statement type %s.', $statement::class)),
        };
    }

    // -----------------------------------------------------------------
    // SELECT
    // -----------------------------------------------------------------

    /** @param list<mixed> $parameters */
    private function executeSelect(SelectStatement $statement, array $parameters): QueryResult
    {
        if ($statement->from === null) {
            if ($statement->groupBy !== [] || $statement->having !== null) {
                throw new ExecutionException('GROUP BY and HAVING require a FROM clause.');
            }

            return $this->executeSelectWithoutFrom($statement, $parameters);
        }

        if ($statement->from instanceof TableReference) {
            return $this->executeSelectFromTable($statement, $statement->from, $parameters);
        }

        if ($statement->from instanceof Join) {
            return $this->executeSelectFromJoin($statement, $statement->from, $parameters);
        }

        throw new ExecutionException('Derived tables are not supported yet.');
    }

    /** @param list<mixed> $parameters */
    private function executeSelectFromTable(SelectStatement $statement, TableReference $reference, array $parameters): QueryResult
    {
        $table = $this->database->table($reference->table);
        $items = $this->expandStars($statement->columns, $table, $reference->referenceName());
        $labels = $this->labelsFor($items);

        $pipeline = $this->selectSource($table, $statement->where, $parameters);
        $pipeline = $this->lockForRead($pipeline, $table->name, $pipeline instanceof SeqScan);
        $contextFor = static fn (Row $row): EvaluationContext => new RowContext($row, $table->name, $reference->alias, $parameters);

        return $this->finishSelect($pipeline, $contextFor, $statement, $items, $labels);
    }

    /** @param list<mixed> $parameters */
    private function executeSelectFromJoin(SelectStatement $statement, Join $from, array $parameters): QueryResult
    {
        foreach ($statement->columns as $item) {
            if ($item->expression instanceof Star) {
                throw new ExecutionException('SELECT * is not supported for JOIN queries yet; list the columns explicitly.');
            }
        }

        $pipeline = $this->buildJoinPipeline($from, $parameters);
        $contextFor = static fn (Row $row): EvaluationContext => new QualifiedRowContext($row, $parameters);
        $labels = $this->labelsFor($statement->columns);

        return $this->finishSelect($pipeline, $contextFor, $statement, $statement->columns, $labels);
    }

    /**
     * The shared tail of both FROM shapes above, once each has built its
     * own source pipeline and the right kind of evaluation context: apply
     * WHERE, then either GROUP BY/HAVING/aggregates (via `Aggregate`, which
     * already produces the final labelled rows) or a plain `Project`, then
     * DISTINCT, then LIMIT/OFFSET. `ORDER BY` runs *before* projection for
     * a plain query (so it can reference a column that was never
     * selected), and *after* aggregation for a grouped one (so it can
     * reference an aggregate's alias, which exists only once computed).
     *
     * @param Closure(Row): EvaluationContext $contextFor
     * @param list<SelectItem>                $items
     * @param list<string>                    $labels
     */
    private function finishSelect(Operator $pipeline, Closure $contextFor, SelectStatement $statement, array $items, array $labels): QueryResult
    {
        if ($statement->where !== null) {
            $pipeline = new Filter($pipeline, $statement->where, $this->evaluator, $contextFor);
        }

        if ($statement->groupBy !== [] || $statement->having !== null || $this->containsAggregate($items)) {
            $pipeline = new Aggregate($pipeline, $statement->groupBy, $items, $labels, $statement->having, $this->evaluator, $contextFor);

            if ($statement->orderBy !== []) {
                $outputContext = static fn (Row $row): EvaluationContext => new RowContext($row);
                $pipeline = new Sort($pipeline, $statement->orderBy, $this->evaluator, $outputContext);
            }
        } else {
            if ($statement->orderBy !== []) {
                $pipeline = new Sort($pipeline, $statement->orderBy, $this->evaluator, $contextFor);
            }

            $pipeline = new Project($pipeline, $items, $labels, $this->evaluator, $contextFor);
        }

        if ($statement->distinct) {
            $pipeline = new Distinct($pipeline);
        }

        $pipeline = new Limit($pipeline, $statement->limit, $statement->offset ?? 0);

        return new QueryResult($labels, $this->stripKeys($pipeline));
    }

    /**
     * A `SeqScan`, unless the `WHERE` clause (or the first conjunct of an
     * `AND` chain) is a `column <op> constant` comparison against a column
     * with a single-column index, in which case an `IndexScan` reads only
     * the matching rows. `Filter` still runs afterward regardless — this
     * only narrows what it has to look at, it never has to be the *whole*
     * answer to be safe to use, which is what keeps this rule this small.
     *
     * @param list<mixed> $parameters
     */
    private function selectSource(Table $table, ?Expression $where, array $parameters): Operator
    {
        $heap = $this->database->heapFile($table->name);
        $predicate = $where === null ? null : $this->indexablePredicate($where);
        $indexName = $predicate === null ? null : $this->singleColumnIndexNameFor($table, $predicate['column']);

        if ($predicate === null || $indexName === null) {
            return new SeqScan($table, $heap);
        }

        $index = $this->database->index($table->name, $indexName);
        $value = $this->evaluator->evaluate($predicate['value'], new RowContext(parameters: $parameters));

        return match ($predicate['op']) {
            'eq' => IndexScan::equals($table, $heap, $index, $value),
            'lt' => IndexScan::range($table, $heap, $index, null, true, $value, false),
            'lte' => IndexScan::range($table, $heap, $index, null, true, $value, true),
            'gt' => IndexScan::range($table, $heap, $index, $value, false, null, true),
            'gte' => IndexScan::range($table, $heap, $index, $value, true, null, true),
        };
    }

    /**
     * @return array{column: string, op: 'eq'|'lt'|'lte'|'gt'|'gte', value: Expression}|null
     */
    private function indexablePredicate(Expression $where): ?array
    {
        if (!$where instanceof BinaryOp) {
            return null;
        }

        if ($where->operator === BinaryOperator::AND) {
            return $this->indexablePredicate($where->left) ?? $this->indexablePredicate($where->right);
        }

        $op = match ($where->operator) {
            BinaryOperator::EQUAL => 'eq',
            BinaryOperator::LESS_THAN => 'lt',
            BinaryOperator::LESS_THAN_OR_EQUAL => 'lte',
            BinaryOperator::GREATER_THAN => 'gt',
            BinaryOperator::GREATER_THAN_OR_EQUAL => 'gte',
            default => null,
        };

        if ($op === null || !$where->left instanceof ColumnRef || !$this->isConstant($where->right)) {
            return null;
        }

        return ['column' => $where->left->column, 'op' => $op, 'value' => $where->right];
    }

    private function isConstant(Expression $expression): bool
    {
        return $expression instanceof Literal || $expression instanceof Placeholder;
    }

    private function singleColumnIndexNameFor(Table $table, string $column): ?string
    {
        foreach ($table->indexes() as $definition) {
            if ($definition->columns() === [$column]) {
                return $definition->name;
            }
        }

        return null;
    }

    /**
     * `REPEATABLE_READ`/`SERIALIZABLE`'s read-locking. A no-op outside an
     * explicit transaction, or under `READ_COMMITTED` inside one: an
     * isolation level's guarantee is about what a *later* statement in the
     * same transaction sees, and an autocommit `SELECT` has no later
     * statement of its own to protect. `SERIALIZABLE` additionally takes a
     * shared table lock on a full scan, to block another transaction's
     * `INSERT` from creating a phantom row this transaction would have
     * matched on a re-scan; an `IndexScan` never needs it, since a value
     * outside its range could never have matched anyway.
     */
    private function lockForRead(Operator $pipeline, string $table, bool $isFullScan): Operator
    {
        $tx = $this->transactions->current();

        if ($tx === null || $tx->isolationLevel === IsolationLevel::READ_COMMITTED) {
            return $pipeline;
        }

        if ($tx->isolationLevel === IsolationLevel::SERIALIZABLE && $isFullScan) {
            $this->locks->acquireTableLock($table, $tx->id, LockMode::SHARED);
        }

        return new LockRows($pipeline, $this->locks, $table, $tx->id, LockMode::SHARED);
    }

    // -----------------------------------------------------------------
    // JOIN
    // -----------------------------------------------------------------

    /** @param list<mixed> $parameters */
    private function buildJoinPipeline(FromItem $from, array $parameters): Operator
    {
        if ($from instanceof TableReference) {
            $table = $this->database->table($from->table);
            $heap = $this->database->heapFile($from->table);

            return new Qualify(new SeqScan($table, $heap), $from->referenceName());
        }

        if (!$from instanceof Join) {
            throw new ExecutionException('Derived tables are not supported yet.');
        }

        $left = $this->buildJoinPipeline($from->left, $parameters);
        $right = $this->buildJoinPipeline($from->right, $parameters);

        if ($from->type === JoinType::INNER) {
            $equiJoin = $this->equiJoinKeys($from);

            return $equiJoin !== null
                ? new HashJoin($left, $right, $equiJoin[0], $equiJoin[1])
                : new NestedLoopJoin($left, $right, false, $from->on, $this->evaluator, $parameters, []);
        }

        if ($from->type === JoinType::LEFT) {
            return new NestedLoopJoin($left, $right, true, $from->on, $this->evaluator, $parameters, $this->qualifiedColumnKeys($from->right));
        }

        // RIGHT JOIN a b ON x == LEFT JOIN b a ON x: the ON expression does
        // not care which physical side a value came from, so swapping
        // which input is "left" reuses NestedLoopJoin's LEFT handling
        // without a third case in that class.
        return new NestedLoopJoin($right, $left, true, $from->on, $this->evaluator, $parameters, $this->qualifiedColumnKeys($from->left));
    }

    /** @return array{0: string, 1: string}|null qualified left key, then qualified right key */
    private function equiJoinKeys(Join $from): ?array
    {
        if (!$from->on instanceof BinaryOp
            || $from->on->operator !== BinaryOperator::EQUAL
            || !$from->on->left instanceof ColumnRef
            || !$from->on->right instanceof ColumnRef
            || $from->on->left->qualifier === null
            || $from->on->right->qualifier === null
        ) {
            return null;
        }

        $leftRefs = $this->tableRefs($from->left);
        $rightRefs = $this->tableRefs($from->right);
        $a = $from->on->left;
        $b = $from->on->right;

        if (in_array($a->qualifier, $leftRefs, true) && in_array($b->qualifier, $rightRefs, true)) {
            return ["{$a->qualifier}.{$a->column}", "{$b->qualifier}.{$b->column}"];
        }

        if (in_array($b->qualifier, $leftRefs, true) && in_array($a->qualifier, $rightRefs, true)) {
            return ["{$b->qualifier}.{$b->column}", "{$a->qualifier}.{$a->column}"];
        }

        return null;
    }

    /** @return list<string> */
    private function qualifiedColumnKeys(FromItem $from): array
    {
        if ($from instanceof TableReference) {
            $table = $this->database->table($from->table);

            return array_map(static fn (string $column): string => $from->referenceName() . '.' . $column, $table->columnNames());
        }

        if ($from instanceof Join) {
            return [...$this->qualifiedColumnKeys($from->left), ...$this->qualifiedColumnKeys($from->right)];
        }

        throw new ExecutionException('Derived tables are not supported yet.');
    }

    /** @return list<string> */
    private function tableRefs(FromItem $from): array
    {
        if ($from instanceof TableReference) {
            return [$from->referenceName()];
        }

        if ($from instanceof Join) {
            return [...$this->tableRefs($from->left), ...$this->tableRefs($from->right)];
        }

        throw new ExecutionException('Derived tables are not supported yet.');
    }

    // -----------------------------------------------------------------
    // GROUP BY / aggregate detection
    // -----------------------------------------------------------------

    /** @param list<SelectItem> $items */
    private function containsAggregate(array $items): bool
    {
        foreach ($items as $item) {
            if ($this->expressionContainsAggregate($item->expression)) {
                return true;
            }
        }

        return false;
    }

    private function expressionContainsAggregate(Expression $expression): bool
    {
        if ($expression instanceof FunctionCall) {
            return in_array(strtoupper($expression->name), Aggregate::AGGREGATE_FUNCTIONS, true);
        }

        if ($expression instanceof BinaryOp) {
            return $this->expressionContainsAggregate($expression->left) || $this->expressionContainsAggregate($expression->right);
        }

        if ($expression instanceof UnaryOp) {
            return $this->expressionContainsAggregate($expression->operand);
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Helpers shared by every FROM shape
    // -----------------------------------------------------------------

    /** @param list<mixed> $parameters */
    private function executeSelectWithoutFrom(SelectStatement $statement, array $parameters): QueryResult
    {
        foreach ($statement->columns as $item) {
            if ($item->expression instanceof Star) {
                throw new ExecutionException('SELECT * requires a FROM clause.');
            }
        }

        $labels = $this->labelsFor($statement->columns);
        $context = new RowContext(parameters: $parameters);
        $values = [];

        foreach ($statement->columns as $i => $item) {
            $values[$labels[$i]] = $this->evaluator->evaluate($item->expression, $context);
        }

        return new QueryResult($labels, [new Row($values)]);
    }

    /**
     * Replaces a bare `*`/`t.*` with one `SelectItem` per column of the
     * table, in table order — the only expansion `Project` needs to
     * remain ignorant of any schema. Only used for a single-table `FROM`;
     * see `executeSelectFromJoin()` for why a joined query disallows `*`
     * instead of expanding it.
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
            static fn (SelectItem $item, int $i): string => Project::label($item, $i),
            $items,
            array_keys($items),
        );
    }

    /**
     * Drops an operator's RecordId keys for the client-facing result — a
     * SELECT result is just rows, no addresses attached.
     *
     * @return iterable<Row>
     */
    private function stripKeys(Operator $operator): iterable
    {
        foreach ($operator as $row) {
            yield $row;
        }
    }

    // -----------------------------------------------------------------
    // INSERT / UPDATE / DELETE
    // -----------------------------------------------------------------

    /** @param list<mixed> $parameters */
    private function executeInsert(InsertStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);
        $columns = $statement->columns ?? $table->columnNames();
        $context = new RowContext(parameters: $parameters);

        return $this->withTransaction(function () use ($statement, $table, $heap, $columns, $context): int {
            $txId = $this->currentTransactionId();

            // An exclusive table lock, held until commit/rollback like every
            // other lock here: the counterpart to the shared one a
            // SERIALIZABLE full scan takes (see lockForRead()). Neither
            // conflicts with a plain row lock, so this only ever actually
            // blocks against another INSERT or a concurrent SERIALIZABLE
            // scan - the phantom this exists to prevent.
            $this->locks->acquireTableLock($table->name, $txId, LockMode::EXCLUSIVE);

            foreach ($statement->rows as $values) {
                if (count($columns) !== count($values)) {
                    throw new ExecutionException(sprintf(
                        'INSERT into "%s" names %d column(s) but gives %d value(s).',
                        $statement->table,
                        count($columns),
                        count($values),
                    ));
                }

                $row = new Row(array_combine(
                    $columns,
                    array_map(fn ($expression) => $this->evaluator->evaluate($expression, $context), $values),
                ));

                // Resolved to a row with every column present (defaults
                // applied, NOT NULL checked) before indexes ever see it, so a
                // unique index enforces the value that actually gets stored,
                // not just the ones the statement happened to name.
                $resolved = $table->rowFromValues($table->valuesFromRow($row));

                $this->indexMaintainer->assertUniqueForInsert($table, $resolved);
                $id = $heap->insert($table->serializeRow($resolved));
                $this->indexMaintainer->afterInsert($table, $resolved, $id);

                $this->locks->acquireRowLock($table->name, $id, $txId, LockMode::EXCLUSIVE);
                $this->transactions->logInsert($table->name, $id, $resolved->toArray());
            }

            return count($statement->rows);
        });
    }

    /** @param list<mixed> $parameters */
    private function executeUpdate(UpdateStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);

        return $this->withTransaction(function () use ($statement, $table, $heap, $parameters): int {
            $txId = $this->currentTransactionId();

            // Collected - and uniqueness-checked - before anything is written:
            // a row that grows past its page moves, and HeapFile::insert()
            // places the moved copy at the end of the file - possibly onto a
            // page this same scan has not reached yet, which would visit and
            // update it a second time. See DECISIONS.md. Checking every match
            // up front, before any write, also means a uniqueness violation
            // anywhere in the statement leaves every row untouched rather than
            // leaving earlier matches already changed. Locks are taken here
            // too, as each candidate is found, rather than only once it is
            // written.
            $writes = [];

            foreach ($heap->scan() as $id => $record) {
                $oldRow = $table->deserializeRow($record);
                $context = new RowContext($oldRow, $statement->table, parameters: $parameters);

                if ($statement->where !== null && !$this->evaluator->isTrue($this->evaluator->evaluate($statement->where, $context))) {
                    continue;
                }

                $this->locks->acquireRowLock($table->name, $id, $txId, LockMode::EXCLUSIVE);

                $values = $oldRow->toArray();

                foreach ($statement->assignments as $assignment) {
                    if (!$table->hasColumn($assignment->column)) {
                        throw new ExecutionException(sprintf('Table "%s" has no column "%s".', $statement->table, $assignment->column));
                    }

                    $values[$assignment->column] = $this->evaluator->evaluate($assignment->value, $context);
                }

                $newRow = $table->rowFromValues($table->valuesFromRow(new Row($values)));
                $this->indexMaintainer->assertUniqueForUpdate($table, $newRow, $id);

                $writes[] = ['id' => $id, 'oldRow' => $oldRow, 'newRow' => $newRow, 'record' => $table->serializeRow($newRow)];
            }

            foreach ($writes as $write) {
                $newId = $heap->update($write['id'], $write['record']);
                $this->indexMaintainer->afterDelete($table, $write['oldRow'], $write['id']);
                $this->indexMaintainer->afterInsert($table, $write['newRow'], $newId);
                $this->transactions->logUpdate($table->name, $newId, $write['oldRow']->toArray(), $write['newRow']->toArray());
            }

            return count($writes);
        });
    }

    /** @param list<mixed> $parameters */
    private function executeDelete(DeleteStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);

        return $this->withTransaction(function () use ($statement, $table, $heap, $parameters): int {
            $txId = $this->currentTransactionId();

            /** @var list<array{id: RecordId, row: Row}> $matches */
            $matches = [];

            foreach ($heap->scan() as $id => $record) {
                $row = $table->deserializeRow($record);

                if ($statement->where !== null) {
                    $context = new RowContext($row, $statement->table, parameters: $parameters);

                    if (!$this->evaluator->isTrue($this->evaluator->evaluate($statement->where, $context))) {
                        continue;
                    }
                }

                $this->locks->acquireRowLock($table->name, $id, $txId, LockMode::EXCLUSIVE);
                $matches[] = ['id' => $id, 'row' => $row];
            }

            foreach ($matches as $match) {
                $heap->delete($match['id']);
                $this->indexMaintainer->afterDelete($table, $match['row'], $match['id']);
                $this->transactions->logDelete($table->name, $match['id'], $match['row']->toArray());
            }

            return count($matches);
        });
    }

    /**
     * Runs $work inside a transaction: the caller's own, if `BEGIN` already
     * opened one, or — for a bare statement — one opened and committed (or
     * rolled back, on failure) around this call alone. This is what gives a
     * multi-row `INSERT` statement-level atomicity for the first time: every
     * row it writes now commits together, or none of them survive.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function withTransaction(Closure $work): mixed
    {
        $autocommit = !$this->transactions->inTransaction();

        if ($autocommit) {
            $this->transactions->begin();
        }

        try {
            $result = $work();
        } catch (Throwable $e) {
            if ($autocommit) {
                $this->transactions->rollback();
            }

            throw $e;
        }

        if ($autocommit) {
            $this->transactions->commit();
        }

        return $result;
    }

    private function currentTransactionId(): int
    {
        $tx = $this->transactions->current() ?? throw new ExecutionException('No transaction is active.');

        return $tx->id;
    }

    // -----------------------------------------------------------------
    // DDL
    // -----------------------------------------------------------------

    private function executeCreateTable(CreateTableStatement $statement): null
    {
        if ($statement->ifNotExists && $this->database->hasTable($statement->table)) {
            return null;
        }

        $this->database->createTable($this->tableBuilder->build($statement));

        return null;
    }

    private function executeDropTable(DropTableStatement $statement): null
    {
        if ($statement->ifExists && !$this->database->hasTable($statement->table)) {
            return null;
        }

        $this->database->dropTable($statement->table);

        return null;
    }

    private function executeCreateIndex(CreateIndexStatement $statement): null
    {
        $this->database->addIndex(
            $statement->table,
            new IndexDefinition($statement->name, $statement->columns, $statement->unique),
        );

        if (count($statement->columns) !== 1) {
            return null; // recorded, but see BTreeIndex/DECISIONS.md: composite indexes have no backing file yet
        }

        $table = $this->database->table($statement->table);
        $column = $statement->columns[0];
        $index = $this->database->index($statement->table, $statement->name);

        foreach ($this->database->heapFile($statement->table)->scan() as $id => $record) {
            $index->insert($table->deserializeRow($record)->get($column), $id);
        }

        return null;
    }

    private function executeDropIndex(DropIndexStatement $statement): null
    {
        $this->database->dropIndex($statement->table, $statement->name);

        return null;
    }

    // -----------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------

    private function executeBegin(BeginStatement $statement): null
    {
        $this->transactions->begin($statement->isolationLevel ?? IsolationLevel::READ_COMMITTED);

        return null;
    }

    private function executeCommit(): null
    {
        $this->transactions->commit();

        return null;
    }

    private function executeRollback(): null
    {
        $this->transactions->rollback();

        return null;
    }

    private function executeSavepoint(SavepointStatement $statement): null
    {
        $this->transactions->savepoint($statement->name);

        return null;
    }

    private function executeReleaseSavepoint(ReleaseSavepointStatement $statement): null
    {
        $this->transactions->releaseSavepoint($statement->name);

        return null;
    }

    private function executeRollbackToSavepoint(RollbackToSavepointStatement $statement): null
    {
        $this->transactions->rollbackToSavepoint($statement->name);

        return null;
    }

    /**
     * `TransactionManager`'s undo handler: physically reverses one logged
     * change through the same `Table`/`HeapFile`/`IndexMaintainer`
     * machinery a fresh `INSERT`/`UPDATE`/`DELETE` uses, rather than
     * restoring raw page bytes. Only ever called by `TransactionManager`
     * (`rollback()`, `rollbackToSavepoint()`, `recover()`), and only ever
     * with an `INSERT`/`UPDATE`/`DELETE` record — see its own docblock for
     * why nothing else it might log ever reaches here.
     */
    private function undo(WalRecord $record): void
    {
        match ($record->operation) {
            WalOperation::INSERT => $this->undoInsert($record),
            WalOperation::UPDATE => $this->undoUpdate($record),
            WalOperation::DELETE => $this->undoDelete($record),
            default => throw new ExecutionException(sprintf('Cannot undo a %s record.', $record->operation->value)),
        };
    }

    private function undoInsert(WalRecord $record): void
    {
        $table = $this->database->table($record->table);
        $heap = $this->database->heapFile($record->table);
        $row = new Row($record->after);

        $heap->delete($record->recordId);
        $this->indexMaintainer->afterDelete($table, $row, $record->recordId);
    }

    private function undoDelete(WalRecord $record): void
    {
        $table = $this->database->table($record->table);
        $heap = $this->database->heapFile($record->table);
        $row = new Row($record->before);

        // The row's slot was freed by the delete this reverses, so it
        // comes back at whatever slot HeapFile hands out next - not
        // necessarily the one it left. RecordId is an address, not the
        // row's identity, so this loses nothing the row's own columns
        // did not already carry.
        $newId = $heap->insert($table->serializeRow($row));
        $this->indexMaintainer->afterInsert($table, $row, $newId);
    }

    private function undoUpdate(WalRecord $record): void
    {
        $table = $this->database->table($record->table);
        $heap = $this->database->heapFile($record->table);
        $before = new Row($record->before);
        $after = new Row($record->after);

        $newId = $heap->update($record->recordId, $table->serializeRow($before));
        $this->indexMaintainer->afterDelete($table, $after, $record->recordId);
        $this->indexMaintainer->afterInsert($table, $before, $newId);
    }
}

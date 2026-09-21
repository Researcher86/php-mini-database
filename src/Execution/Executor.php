<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use Closure;
use PhpMiniDatabase\Exception\ConstraintViolationException;
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
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
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
use PhpMiniDatabase\Sql\Ast\ExplainStatement;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
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
use PhpMiniDatabase\Sql\Optimizer\Optimizer;
use PhpMiniDatabase\Sql\Optimizer\PlanContext;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Sql\Planner\LogicalPlan;
use PhpMiniDatabase\Sql\Planner\Plan\Aggregate as PlanAggregate;
use PhpMiniDatabase\Sql\Planner\Plan\Distinct as PlanDistinct;
use PhpMiniDatabase\Sql\Planner\Plan\Filter as PlanFilter;
use PhpMiniDatabase\Sql\Planner\Plan\Join as PlanJoin;
use PhpMiniDatabase\Sql\Planner\Plan\Limit as PlanLimit;
use PhpMiniDatabase\Sql\Planner\Plan\Project as PlanProject;
use PhpMiniDatabase\Sql\Planner\Plan\Scan as PlanScan;
use PhpMiniDatabase\Sql\Planner\Plan\Sort as PlanSort;
use PhpMiniDatabase\Sql\Planner\PlannedQuery;
use PhpMiniDatabase\Sql\Planner\Planner;
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
 * `execute()` returns whichever of the four shapes a statement produces:
 * a `QueryResult` for `SELECT`, the number of affected rows (`int`) for
 * `INSERT`/`UPDATE`/`DELETE`, `null` for a DDL or transaction-control
 * statement, or a `QueryResult` describing a plan for `EXPLAIN`. A caller
 * that already knows which kind it sent can narrow the result itself;
 * `run()` is the convenience for a caller that has SQL text and nothing
 * more specific to do with the answer.
 *
 * `INSERT`/`UPDATE`/`DELETE` keep every single-column index in step via
 * `IndexMaintainer` — uniqueness is checked before anything is written, not
 * after (see its own docblock). `ConstraintEnforcer` checks `CHECK` and the
 * *referencing* side of a `FOREIGN KEY` the same way, before the write; the
 * *referenced* side — what happens to a child row when the parent it
 * points at is deleted or its key changes — is `cascadeBeforeDelete()`/
 * `cascadeBeforeUpdate()` here instead, since acting on `ON DELETE
 * CASCADE`/`SET NULL` needs the heap/index/WAL machinery only this class
 * has (Phase 10).
 *
 * A `SELECT` with a `FROM` clause runs through `Sql\Planner\Planner` and
 * `Sql\Optimizer\Optimizer` (Phase 9): `plan()` builds the `LogicalPlan`
 * PLAN.md's Milestone 9 asks for, `Optimizer` rewrites it (predicate
 * pushdown, constant folding, index selection, join reordering — see each
 * `Sql\Optimizer\Rule\*`), and `compile()` is the one place a `Plan\*` node
 * ever becomes the `Execution\Operator\*` it describes. `EXPLAIN` stops
 * after the same two steps and prints the result instead of compiling it.
 *
 * A query against a `JOIN` evaluates `WHERE`/`ON`/the select list against
 * `QualifiedRowContext` (columns named `"ref.column"`) rather than the
 * plain `RowContext` a single table uses — and, for that reason, does not
 * support a bare `SELECT *`/`t.*` yet: expanding a star needs to enumerate
 * every table in the join, which `Planner` does not do for one. List
 * columns explicitly instead.
 *
 * What this phase does *not* do, deliberately, and reports clearly rather
 * than silently mishandling: derived tables and subqueries (still need a
 * planner that can run a nested `SELECT`); `ALTER TABLE`; a cascade cycle,
 * which recurses until the call stack gives out rather than being detected.
 * See DECISIONS.md for the reasoning behind each.
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

    private ConstraintEnforcer $constraints;

    private LockManager $locks;

    private TransactionManager $transactions;

    private Planner $planner;

    private Optimizer $optimizer;

    public function __construct(
        private Database $database,
        private Evaluator $evaluator = new Evaluator(),
        private TableBuilder $tableBuilder = new TableBuilder(),
    ) {
        $this->indexMaintainer = new IndexMaintainer($database);
        $this->constraints = new ConstraintEnforcer($database, $evaluator);
        $this->locks = $database->locks();
        $this->transactions = $database->transactions();
        $this->transactions->setUndoHandler($this->undo(...));
        $this->transactions->recover();
        $this->planner = new Planner($database);
        $this->optimizer = new Optimizer();
    }

    /** @param list<mixed> $parameters */
    public function run(string $sql, array $parameters = []): QueryResult|int|null
    {
        return $this->execute(Parser::parseOne($sql), $parameters);
    }

    /**
     * Whether *some* transaction is currently open — system-wide, not
     * per-caller, since `Database` allows only one at a time (see
     * DECISIONS.md, Phase 8). `Network\Session` uses this to notice when
     * its own statement is the one that opened or closed it, not to ask
     * whether it personally owns whatever is open.
     */
    public function inTransaction(): bool
    {
        return $this->transactions->inTransaction();
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
            $statement instanceof ExplainStatement => $this->executeExplain($statement),
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

        return $this->runPlan($this->plan($statement), $statement, $parameters);
    }

    /** Plans and optimizes a `SELECT` — the shared first half of running it and of `EXPLAIN`ing it. */
    private function plan(SelectStatement $statement): PlannedQuery
    {
        $planned = $this->planner->plan($statement);

        return new PlannedQuery(
            $this->optimizer->optimize($planned->plan, new PlanContext($this->database)),
            $planned->labels,
        );
    }

    /** @param list<mixed> $parameters */
    private function runPlan(PlannedQuery $planned, SelectStatement $statement, array $parameters): QueryResult
    {
        $isJoin = !$statement->from instanceof TableReference;
        $contextFor = $isJoin
            ? static fn (Row $row): EvaluationContext => new QualifiedRowContext($row, $parameters)
            : $this->tableContextFor($statement->from, $parameters);

        $operator = $this->compile($planned->plan, $isJoin, $parameters, $contextFor);

        return new QueryResult($planned->labels, $this->stripKeys($operator));
    }

    /** @param list<mixed> $parameters */
    private function tableContextFor(TableReference $reference, array $parameters): Closure
    {
        $table = $this->database->table($reference->table);

        return static fn (Row $row): EvaluationContext => new RowContext($row, $table->name, $reference->alias, $parameters);
    }

    /**
     * Turns an optimized `LogicalPlan` into the `Operator` pipeline that
     * actually runs it — the one place a `Sql\Planner\Plan\*` node ever
     * becomes the `Execution\Operator\*` it describes.
     *
     * `$isJoin` is decided once, in `runPlan()`, and threaded through
     * unchanged: it is what a `Plan\Scan` uses to decide whether it needs
     * `Qualify` wrapped around it, and what every other node uses to pick
     * `$contextFor`. The one exception is a `Plan\Sort` sitting *directly*
     * above a `Plan\Aggregate`: an aggregate's output row is always flat
     * and already labelled, whether or not its source was a join, so that
     * `Sort` always gets a plain `RowContext` regardless of `$isJoin` — see
     * `Sql\Planner\Planner::tail()`'s docblock for why `ORDER BY` sits in a
     * different place in the pipeline for a grouped query at all.
     *
     * @param list<mixed> $parameters
     */
    private function compile(LogicalPlan $plan, bool $isJoin, array $parameters, Closure $contextFor): Operator
    {
        return match (true) {
            $plan instanceof PlanScan => $this->compileScan($plan, $isJoin, $parameters),
            $plan instanceof PlanFilter => new Filter(
                $this->compile($plan->source, $isJoin, $parameters, $contextFor),
                $plan->predicate,
                $this->evaluator,
                $contextFor,
            ),
            $plan instanceof PlanJoin => $this->compileJoin($plan, $isJoin, $parameters, $contextFor),
            $plan instanceof PlanAggregate => new Aggregate(
                $this->compile($plan->source, $isJoin, $parameters, $contextFor),
                $plan->groupBy,
                $plan->items,
                $plan->labels,
                $plan->having,
                $this->evaluator,
                $contextFor,
            ),
            $plan instanceof PlanSort => new Sort(
                $this->compile($plan->source, $isJoin, $parameters, $contextFor),
                $plan->orderBy,
                $this->evaluator,
                $plan->source instanceof PlanAggregate ? static fn (Row $row): EvaluationContext => new RowContext($row) : $contextFor,
            ),
            $plan instanceof PlanProject => new Project(
                $this->compile($plan->source, $isJoin, $parameters, $contextFor),
                $plan->items,
                $plan->labels,
                $this->evaluator,
                $contextFor,
            ),
            $plan instanceof PlanDistinct => new Distinct($this->compile($plan->source, $isJoin, $parameters, $contextFor)),
            $plan instanceof PlanLimit => new Limit($this->compile($plan->source, $isJoin, $parameters, $contextFor), $plan->limit, $plan->offset),
            default => throw new ExecutionException(sprintf('Cannot compile plan node %s.', $plan::class)),
        };
    }

    /** @param list<mixed> $parameters */
    private function compileScan(PlanScan $plan, bool $isJoin, array $parameters): Operator
    {
        $heap = $this->database->heapFile($plan->table->name);

        if ($plan->index === null) {
            $operator = new SeqScan($plan->table, $heap);
        } else {
            $index = $this->database->index($plan->table->name, $plan->index->indexName);
            $value = $this->evaluator->evaluate($plan->index->value, new RowContext(parameters: $parameters));

            $operator = match ($plan->index->operator) {
                'eq' => IndexScan::equals($plan->table, $heap, $index, $value),
                'lt' => IndexScan::range($plan->table, $heap, $index, null, true, $value, false),
                'lte' => IndexScan::range($plan->table, $heap, $index, null, true, $value, true),
                'gt' => IndexScan::range($plan->table, $heap, $index, $value, false, null, true),
                'gte' => IndexScan::range($plan->table, $heap, $index, $value, true, null, true),
            };
        }

        $operator = $this->lockForRead($operator, $plan->table->name, $plan->index === null);

        return $isJoin ? new Qualify($operator, $plan->reference()) : $operator;
    }

    /** @param list<mixed> $parameters */
    private function compileJoin(PlanJoin $plan, bool $isJoin, array $parameters, Closure $contextFor): Operator
    {
        $left = $this->compile($plan->left, $isJoin, $parameters, $contextFor);
        $right = $this->compile($plan->right, $isJoin, $parameters, $contextFor);

        if ($plan->hash !== null) {
            return new HashJoin($left, $right, $plan->hash->leftKey, $plan->hash->rightKey);
        }

        if ($plan->type === JoinType::INNER) {
            return new NestedLoopJoin($left, $right, false, $plan->on, $this->evaluator, $parameters, []);
        }

        if ($plan->type === JoinType::LEFT) {
            return new NestedLoopJoin($left, $right, true, $plan->on, $this->evaluator, $parameters, $this->qualifiedColumnKeys($plan->right));
        }

        // RIGHT JOIN a b ON x == LEFT JOIN b a ON x: the ON expression does
        // not care which physical side a value came from, so swapping
        // which input is "left" reuses NestedLoopJoin's LEFT handling
        // without a third case in that class.
        return new NestedLoopJoin($right, $left, true, $plan->on, $this->evaluator, $parameters, $this->qualifiedColumnKeys($plan->left));
    }

    /** @return list<string> */
    private function qualifiedColumnKeys(LogicalPlan $plan): array
    {
        if ($plan instanceof PlanScan) {
            return array_map(static fn (string $column): string => $plan->reference() . '.' . $column, $plan->table->columnNames());
        }

        if ($plan instanceof PlanJoin) {
            return [...$this->qualifiedColumnKeys($plan->left), ...$this->qualifiedColumnKeys($plan->right)];
        }

        throw new ExecutionException('Derived tables are not supported yet.');
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
    // EXPLAIN
    // -----------------------------------------------------------------

    private function executeExplain(ExplainStatement $statement): QueryResult
    {
        if ($statement->statement->from === null) {
            throw new ExecutionException('EXPLAIN requires a FROM clause.');
        }

        $lines = [];
        $this->explainLines($this->plan($statement->statement)->plan, 0, $lines);

        return new QueryResult(['plan'], array_map(static fn (string $line): Row => new Row(['plan' => $line]), $lines));
    }

    /** @param list<string> $lines */
    private function explainLines(LogicalPlan $plan, int $depth, array &$lines): void
    {
        $lines[] = str_repeat('  ', $depth) . $plan->describe();

        foreach ($plan->children() as $child) {
            $this->explainLines($child, $depth + 1, $lines);
        }
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

                $this->constraints->assertCheckConstraints($table, $resolved);
                $this->constraints->assertForeignKeysOnWrite($table, $resolved);
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
                $this->constraints->assertCheckConstraints($table, $newRow);
                $this->constraints->assertForeignKeysOnWrite($table, $newRow);
                $this->indexMaintainer->assertUniqueForUpdate($table, $newRow, $id);

                $writes[] = ['id' => $id, 'oldRow' => $oldRow, 'newRow' => $newRow];
            }

            foreach ($writes as $write) {
                // Checked (and, for CASCADE/SET NULL, acted on) before this
                // row's own update is applied - see cascadeBeforeUpdate()'s
                // docblock for why the order matters.
                $this->cascadeBeforeUpdate($table, $write['oldRow'], $write['newRow'], $txId);
                $this->physicallyUpdateRow($table, $write['id'], $write['oldRow'], $write['newRow'], $txId);
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
                // Checked (and, for CASCADE/SET NULL, acted on) before this
                // row itself is deleted - see cascadeBeforeDelete()'s
                // docblock for why the order matters.
                $this->cascadeBeforeDelete($table, $match['row'], $txId);
                $this->physicallyDeleteRow($table, $match['id'], $match['row'], $txId);
            }

            return count($matches);
        });
    }

    /**
     * The physical half of deleting one row: heap, indexes, WAL — exactly
     * what the pre-Phase-10 `executeDelete()` did inline. Split out so
     * `cascadeBeforeDelete()` can call it for a `CASCADE`d child row too,
     * without duplicating it.
     */
    private function physicallyDeleteRow(Table $table, RecordId $id, Row $row, int $txId): void
    {
        $this->database->heapFile($table->name)->delete($id);
        $this->indexMaintainer->afterDelete($table, $row, $id);
        $this->transactions->logDelete($table->name, $id, $row->toArray());
    }

    /**
     * The physical half of updating one row: heap, indexes, WAL — exactly
     * what the pre-Phase-10 `executeUpdate()` did inline. Split out for the
     * same reason as `physicallyDeleteRow()`.
     */
    private function physicallyUpdateRow(Table $table, RecordId $id, Row $oldRow, Row $newRow, int $txId): RecordId
    {
        $newId = $this->database->heapFile($table->name)->update($id, $table->serializeRow($newRow));
        $this->indexMaintainer->afterDelete($table, $oldRow, $id);
        $this->indexMaintainer->afterInsert($table, $newRow, $newId);
        $this->transactions->logUpdate($table->name, $newId, $oldRow->toArray(), $newRow->toArray());

        return $newId;
    }

    /**
     * `FOREIGN KEY ... ON DELETE`'s enforcement: every other table with a
     * foreign key pointing at $table is searched for a row matching
     * $row — a full scan unless the key is single-column and indexed, the
     * same trade-off `ConstraintEnforcer::referencedRowExists()` makes in
     * the other direction. `RESTRICT`/`NO_ACTION` both refuse the delete
     * outright; `CASCADE` deletes the matching child rows (recursively —
     * a child can itself have children); `SET NULL` nulls the child's
     * foreign key columns instead, which fails on its own if those columns
     * are `NOT NULL` (`Table::valuesFromRow()` already checks that, for
     * free).
     *
     * Runs *before* $row is actually removed: a `RESTRICT` that throws
     * must leave $row untouched, and a `CASCADE` that goes on to hit a
     * `RESTRICT` further down the chain must leave every row involved —
     * $row included — untouched too, not partially deleted.
     *
     * A cascade cycle (two tables `CASCADE`-referencing each other, or a
     * table `CASCADE`-referencing itself) is not detected and will recurse
     * until the call stack gives out — a named, narrow gap; see
     * DECISIONS.md.
     */
    private function cascadeBeforeDelete(Table $table, Row $row, int $txId): void
    {
        foreach ($this->referencingForeignKeys($table->name) as [$childTable, $foreignKey]) {
            $keyValues = array_map(static fn (string $column): mixed => $row->get($column), $foreignKey->referencedColumns());

            foreach ($this->matchingChildRows($childTable, $foreignKey, $keyValues) as $child) {
                match ($foreignKey->onDelete) {
                    ReferentialAction::CASCADE => $this->cascadeDeleteChild($childTable, $child['id'], $child['row'], $txId),
                    ReferentialAction::SET_NULL => $this->cascadeNullifyChild($childTable, $child['id'], $child['row'], $foreignKey, $txId),
                    ReferentialAction::RESTRICT, ReferentialAction::NO_ACTION => throw new ConstraintViolationException(sprintf(
                        'Cannot delete from "%s": still referenced by "%s" via "%s".',
                        $table->name,
                        $childTable->name,
                        $foreignKey->name(),
                    )),
                };
            }
        }
    }

    /**
     * `FOREIGN KEY ... ON UPDATE`'s enforcement — the same rules as
     * `cascadeBeforeDelete()`, applied only when $newRow actually changes a
     * value some other table's foreign key references at all; an update to
     * an unrelated column never has to search another table.
     */
    private function cascadeBeforeUpdate(Table $table, Row $oldRow, Row $newRow, int $txId): void
    {
        foreach ($this->referencingForeignKeys($table->name) as [$childTable, $foreignKey]) {
            $oldKeyValues = array_map(static fn (string $column): mixed => $oldRow->get($column), $foreignKey->referencedColumns());
            $newKeyValues = array_map(static fn (string $column): mixed => $newRow->get($column), $foreignKey->referencedColumns());

            if ($oldKeyValues === $newKeyValues) {
                continue;
            }

            foreach ($this->matchingChildRows($childTable, $foreignKey, $oldKeyValues) as $child) {
                match ($foreignKey->onUpdate) {
                    ReferentialAction::CASCADE => $this->cascadeUpdateChildKey($childTable, $child['id'], $child['row'], $foreignKey, $newKeyValues, $txId),
                    ReferentialAction::SET_NULL => $this->cascadeNullifyChild($childTable, $child['id'], $child['row'], $foreignKey, $txId),
                    ReferentialAction::RESTRICT, ReferentialAction::NO_ACTION => throw new ConstraintViolationException(sprintf(
                        'Cannot update "%s": still referenced by "%s" via "%s".',
                        $table->name,
                        $childTable->name,
                        $foreignKey->name(),
                    )),
                };
            }
        }
    }

    private function cascadeDeleteChild(Table $childTable, RecordId $id, Row $row, int $txId): void
    {
        $this->locks->acquireRowLock($childTable->name, $id, $txId, LockMode::EXCLUSIVE);
        $this->cascadeBeforeDelete($childTable, $row, $txId);
        $this->physicallyDeleteRow($childTable, $id, $row, $txId);
    }

    /** @param list<mixed> $newKeyValues in the same order as $foreignKey->columns() */
    private function cascadeUpdateChildKey(Table $childTable, RecordId $id, Row $row, ForeignKey $foreignKey, array $newKeyValues, int $txId): void
    {
        $this->locks->acquireRowLock($childTable->name, $id, $txId, LockMode::EXCLUSIVE);

        $values = $row->toArray();
        foreach ($foreignKey->columns() as $i => $column) {
            $values[$column] = $newKeyValues[$i];
        }

        $newRow = $childTable->rowFromValues($childTable->valuesFromRow(new Row($values)));
        $this->indexMaintainer->assertUniqueForUpdate($childTable, $newRow, $id);
        $this->physicallyUpdateRow($childTable, $id, $row, $newRow, $txId);
    }

    private function cascadeNullifyChild(Table $childTable, RecordId $id, Row $row, ForeignKey $foreignKey, int $txId): void
    {
        $this->locks->acquireRowLock($childTable->name, $id, $txId, LockMode::EXCLUSIVE);

        $values = $row->toArray();
        foreach ($foreignKey->columns() as $column) {
            $values[$column] = null;
        }

        $newRow = $childTable->rowFromValues($childTable->valuesFromRow(new Row($values)));
        $this->indexMaintainer->assertUniqueForUpdate($childTable, $newRow, $id);
        $this->physicallyUpdateRow($childTable, $id, $row, $newRow, $txId);
    }

    /**
     * Every other table with a `FOREIGN KEY` naming $tableName as its
     * `REFERENCES` target, paired with that constraint — what
     * `cascadeBeforeDelete()`/`cascadeBeforeUpdate()` walk to find rows
     * that might dangle. A table can reference itself, which this does not
     * special-case: it shows up in `$this->database->tableNames()` like
     * any other and is searched the same way.
     *
     * @return list<array{0: Table, 1: ForeignKey}>
     */
    private function referencingForeignKeys(string $tableName): array
    {
        $found = [];

        foreach ($this->database->tableNames() as $name) {
            $candidate = $this->database->table($name);

            foreach ($candidate->constraints() as $constraint) {
                if ($constraint instanceof ForeignKey && $constraint->referencedTable === $tableName) {
                    $found[] = [$candidate, $constraint];
                }
            }
        }

        return $found;
    }

    /**
     * Every row of $childTable whose $foreignKey columns match $keyValues —
     * a full scan, collected into an array before the caller acts on any
     * of them, for the same reason `executeUpdate()`'s own scan is: a
     * physical move triggered by acting on one match must not disturb this
     * scan's view of the rest of the file. A row with a `NULL` in any of
     * the foreign key's own columns never matches anything (MATCH SIMPLE,
     * same as `ConstraintEnforcer::assertForeignKeysOnWrite()`).
     *
     * @param list<mixed> $keyValues in the same order as $foreignKey->columns()
     *
     * @return list<array{id: RecordId, row: Row}>
     */
    private function matchingChildRows(Table $childTable, ForeignKey $foreignKey, array $keyValues): array
    {
        $matches = [];

        foreach ($this->database->heapFile($childTable->name)->scan() as $id => $record) {
            $row = $childTable->deserializeRow($record);
            $values = array_map(static fn (string $column): mixed => $row->get($column), $foreignKey->columns());

            if (!in_array(null, $values, true) && $values === $keyValues) {
                $matches[] = ['id' => $id, 'row' => $row];
            }
        }

        return $matches;
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

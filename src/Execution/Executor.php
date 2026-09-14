<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Execution\Operator\Filter;
use PhpMiniDatabase\Execution\Operator\IndexScan;
use PhpMiniDatabase\Execution\Operator\Limit;
use PhpMiniDatabase\Execution\Operator\Operator;
use PhpMiniDatabase\Execution\Operator\Project;
use PhpMiniDatabase\Execution\Operator\SeqScan;
use PhpMiniDatabase\Execution\Operator\Sort;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Ast\AlterTableStatement;
use PhpMiniDatabase\Sql\Ast\CreateIndexStatement;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Ast\DeleteStatement;
use PhpMiniDatabase\Sql\Ast\DropIndexStatement;
use PhpMiniDatabase\Sql\Ast\DropTableStatement;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\From\FromItem;
use PhpMiniDatabase\Sql\Ast\From\TableReference;
use PhpMiniDatabase\Sql\Ast\InsertStatement;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Ast\Statement;
use PhpMiniDatabase\Sql\Ast\UpdateStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Storage\RecordId;

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
 * a single-column index — see `selectSource()` and DECISIONS.md for why
 * this stands in for a real planner rather than becoming one.
 *
 * What this phase does *not* do, deliberately, and reports clearly rather
 * than silently mishandling: `JOIN`, derived tables and subqueries (need
 * the planner and a second scan mechanism — Phases 7 and 9); `GROUP BY`,
 * `HAVING`, `DISTINCT` and aggregate functions (Phase 7); `ALTER TABLE`
 * (Phase 10); enforcing `FOREIGN KEY` and `CHECK` on a write (Phase 10 —
 * `NOT NULL` is enforced by `Schema\Table` itself, and `UNIQUE`/`PRIMARY
 * KEY` by `IndexMaintainer`, since neither needs anything this layer does
 * not already have). See DECISIONS.md for the reasoning behind each.
 */
final readonly class Executor
{
    private IndexMaintainer $indexMaintainer;

    public function __construct(
        private Database $database,
        private Evaluator $evaluator = new Evaluator(),
        private TableBuilder $tableBuilder = new TableBuilder(),
    ) {
        $this->indexMaintainer = new IndexMaintainer($database);
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
        if ($statement->groupBy !== [] || $statement->having !== null || $statement->distinct) {
            throw new ExecutionException('GROUP BY, HAVING and DISTINCT are not supported yet.');
        }

        if ($statement->from === null) {
            return $this->executeSelectWithoutFrom($statement, $parameters);
        }

        $table = $this->soleTable($statement->from);
        $reference = $this->tableReference($statement->from);

        $items = $this->expandStars($statement->columns, $table, $reference->referenceName());
        $labels = $this->labelsFor($items);

        $pipeline = $this->selectSource($table, $statement->where, $parameters);

        if ($statement->where !== null) {
            $pipeline = new Filter($pipeline, $statement->where, $this->evaluator, $table->name, $reference->alias, $parameters);
        }

        if ($statement->orderBy !== []) {
            $pipeline = new Sort($pipeline, $statement->orderBy, $this->evaluator, $table->name, $reference->alias, $parameters);
        }

        $pipeline = new Project($pipeline, $items, $labels, $this->evaluator, $table->name, $reference->alias, $parameters);
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

    private function soleTable(FromItem $from): Table
    {
        if (!$from instanceof TableReference) {
            throw new ExecutionException('JOINs and derived tables are not supported yet.');
        }

        return $this->database->table($from->table);
    }

    private function tableReference(FromItem $from): TableReference
    {
        // soleTable() already refused anything else; this exists only so
        // callers do not repeat the instanceof check it already did.
        return $from instanceof TableReference ? $from : throw new ExecutionException('JOINs and derived tables are not supported yet.');
    }

    /**
     * Replaces a bare `*`/`t.*` with one `SelectItem` per column of the
     * table, in table order — the only expansion `Project` needs to
     * remain ignorant of any schema.
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
        }

        return count($statement->rows);
    }

    /** @param list<mixed> $parameters */
    private function executeUpdate(UpdateStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);

        // Collected - and uniqueness-checked - before anything is written:
        // a row that grows past its page moves, and HeapFile::insert()
        // places the moved copy at the end of the file - possibly onto a
        // page this same scan has not reached yet, which would visit and
        // update it a second time. See DECISIONS.md. Checking every match
        // up front, before any write, also means a uniqueness violation
        // anywhere in the statement leaves every row untouched rather than
        // leaving earlier matches already changed.
        $writes = [];

        foreach ($heap->scan() as $id => $record) {
            $oldRow = $table->deserializeRow($record);
            $context = new RowContext($oldRow, $statement->table, parameters: $parameters);

            if ($statement->where !== null && !$this->evaluator->isTrue($this->evaluator->evaluate($statement->where, $context))) {
                continue;
            }

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
        }

        return count($writes);
    }

    /** @param list<mixed> $parameters */
    private function executeDelete(DeleteStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);

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

            $matches[] = ['id' => $id, 'row' => $row];
        }

        foreach ($matches as $match) {
            $heap->delete($match['id']);
            $this->indexMaintainer->afterDelete($table, $match['row'], $match['id']);
        }

        return count($matches);
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
}

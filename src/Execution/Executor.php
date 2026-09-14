<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Execution\Operator\Filter;
use PhpMiniDatabase\Execution\Operator\Limit;
use PhpMiniDatabase\Execution\Operator\Operator;
use PhpMiniDatabase\Execution\Operator\Project;
use PhpMiniDatabase\Execution\Operator\SeqScan;
use PhpMiniDatabase\Execution\Operator\Sort;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Ast\AlterTableStatement;
use PhpMiniDatabase\Sql\Ast\CreateIndexStatement;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Ast\DeleteStatement;
use PhpMiniDatabase\Sql\Ast\DropIndexStatement;
use PhpMiniDatabase\Sql\Ast\DropTableStatement;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
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
 * What this phase does *not* do, deliberately, and reports clearly rather
 * than silently mishandling: `JOIN`, derived tables and subqueries (need
 * the planner and a second scan mechanism — Phases 7 and 9); `GROUP BY`,
 * `HAVING`, `DISTINCT` and aggregate functions (Phase 7); enforcing
 * `UNIQUE`, `FOREIGN KEY` and `CHECK` on a write (Phase 10 — `NOT NULL` is
 * already enforced, by `Schema\Table` itself, since it needs nothing this
 * layer does not already have). See DECISIONS.md for the reasoning behind
 * each.
 */
final readonly class Executor
{
    public function __construct(
        private Database $database,
        private Evaluator $evaluator = new Evaluator(),
        private TableBuilder $tableBuilder = new TableBuilder(),
    ) {
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
            $statement instanceof AlterTableStatement,
            $statement instanceof CreateIndexStatement,
            $statement instanceof DropIndexStatement => throw new ExecutionException(
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
        $heap = $this->database->heapFile($table->name);
        $reference = $this->tableReference($statement->from);

        $items = $this->expandStars($statement->columns, $table, $reference->referenceName());
        $labels = $this->labelsFor($items);

        $pipeline = new SeqScan($table, $heap);

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

            $heap->insert($table->serializeRow($row));
        }

        return count($statement->rows);
    }

    /** @param list<mixed> $parameters */
    private function executeUpdate(UpdateStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);

        // Collected before anything is written: a row that grows past its
        // page moves, and HeapFile::insert() places the moved copy at the
        // end of the file - possibly onto a page this same scan has not
        // reached yet, which would visit and update it a second time. See
        // DECISIONS.md.
        $writes = [];

        foreach ($heap->scan() as $id => $record) {
            $row = $table->deserializeRow($record);
            $context = new RowContext($row, $statement->table, parameters: $parameters);

            if ($statement->where !== null && !$this->evaluator->isTrue($this->evaluator->evaluate($statement->where, $context))) {
                continue;
            }

            $values = $row->toArray();

            foreach ($statement->assignments as $assignment) {
                if (!$table->hasColumn($assignment->column)) {
                    throw new ExecutionException(sprintf('Table "%s" has no column "%s".', $statement->table, $assignment->column));
                }

                $values[$assignment->column] = $this->evaluator->evaluate($assignment->value, $context);
            }

            $writes[] = ['id' => $id, 'record' => $table->serializeRow(new Row($values))];
        }

        foreach ($writes as $write) {
            $heap->update($write['id'], $write['record']);
        }

        return count($writes);
    }

    /** @param list<mixed> $parameters */
    private function executeDelete(DeleteStatement $statement, array $parameters): int
    {
        $table = $this->database->table($statement->table);
        $heap = $this->database->heapFile($statement->table);

        /** @var list<RecordId> $matches */
        $matches = [];

        foreach ($heap->scan() as $id => $record) {
            if ($statement->where === null) {
                $matches[] = $id;
                continue;
            }

            $context = new RowContext($table->deserializeRow($record), $statement->table, parameters: $parameters);

            if ($this->evaluator->isTrue($this->evaluator->evaluate($statement->where, $context))) {
                $matches[] = $id;
            }
        }

        foreach ($matches as $id) {
            $heap->delete($id);
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
}

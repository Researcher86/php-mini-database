<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PhpMiniDatabase\Schema\Constraint\Constraint;
use PhpMiniDatabase\Schema\Constraint\ForeignKey as SchemaForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\TypeFactory;
use PhpMiniDatabase\Sql\Ast\ColumnDefinition;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\TableConstraint\CheckDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\ForeignKeyDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\PrimaryKeyDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\TableConstraintDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\UniqueDefinition;
use PhpMiniDatabase\Sql\ExpressionPrinter;

/**
 * Turns a parsed `CREATE TABLE` into the `Schema\Table` the catalog stores.
 *
 * A type name is resolved through `TypeFactory` here, for the first time —
 * the parser only ever kept it as text (see DECISIONS.md). A `CHECK`
 * expression is turned back into SQL text through `ExpressionPrinter`, for
 * the same reason `Schema\Constraint\CheckConstraint` needs a string: it
 * predates there being a parser to hand it a tree instead.
 *
 * A literal `DEFAULT` is evaluated once, here, into the value
 * `Column::withDefault()` keeps. A function-call default that would need to
 * be *recomputed per row* — `DEFAULT CURRENT_TIMESTAMP` above all — is
 * refused instead of silently frozen at CREATE TABLE time; see
 * DECISIONS.md for why evaluating it now would be a correctness bug, not a
 * shortcut.
 */
final readonly class TableBuilder
{
    private const DYNAMIC_DEFAULT_FUNCTIONS = ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'];

    public function __construct(
        private TypeFactory $types = new TypeFactory(),
        private ExpressionPrinter $printer = new ExpressionPrinter(),
        private Evaluator $evaluator = new Evaluator(),
    ) {
    }

    public function build(CreateTableStatement $statement): Table
    {
        $columns = [];
        $constraints = [];

        foreach ($statement->columns as $definition) {
            [$column, $inlineConstraints] = $this->column($statement->table, $definition);
            $columns[] = $column;
            array_push($constraints, ...$inlineConstraints);
        }

        foreach ($statement->constraints as $definition) {
            $constraints[] = $this->tableConstraint($statement->table, $definition);
        }

        return new Table(
            $statement->table,
            $this->withPrimaryKeyColumnsNotNull($columns, $constraints),
            $constraints,
            $this->backingIndexes($constraints),
        );
    }

    /**
     * A single-column `PRIMARY KEY` or `UNIQUE` constraint gets a matching
     * `IndexDefinition`, so it has a `BTreeIndex` backing it (Phase 6) the
     * same way an explicit `CREATE INDEX` does — `Executor` builds that
     * file once this `Table` is stored, and every insert checks and
     * maintains it uniformly, whether the constraint that asked for
     * uniqueness was written as `PRIMARY KEY`, `UNIQUE`, or `CREATE INDEX
     * ... UNIQUE`.
     *
     * A *composite* PRIMARY KEY or UNIQUE constraint gets none: `BTreeIndex`
     * only indexes one column (see its own docblock), so a multi-column
     * uniqueness rule is recorded in the schema but not yet backed or
     * enforced by an index — a known, narrow gap, not a silent one.
     *
     * @param list<Constraint> $constraints
     *
     * @return list<IndexDefinition>
     */
    private function backingIndexes(array $constraints): array
    {
        $indexes = [];

        foreach ($constraints as $constraint) {
            $isUniqueness = $constraint instanceof PrimaryKey || $constraint instanceof UniqueConstraint;

            if ($isUniqueness && count($constraint->columns()) === 1) {
                $indexes[] = new IndexDefinition($constraint->name(), $constraint->columns(), unique: true);
            }
        }

        return $indexes;
    }

    /**
     * A `PRIMARY KEY` column is `NOT NULL` whether or not the `CREATE
     * TABLE` said so explicitly — the universal SQL convention, and
     * `Schema\Table` itself refuses a primary key over a nullable column
     * rather than inferring this, so it has to already be true by the time
     * the table is constructed. This covers both spellings a `PRIMARY KEY`
     * can come from: the inline `id INT PRIMARY KEY` shorthand, where
     * nothing else would ever set the flag, and a table-level
     * `PRIMARY KEY (a, b)` naming columns whose own definitions never
     * mentioned it.
     *
     * @param list<Column>     $columns
     * @param list<Constraint> $constraints
     *
     * @return list<Column>
     */
    private function withPrimaryKeyColumnsNotNull(array $columns, array $constraints): array
    {
        $primaryKeyColumns = [];

        foreach ($constraints as $constraint) {
            if ($constraint instanceof PrimaryKey) {
                $primaryKeyColumns = $constraint->columns();
                break;
            }
        }

        if ($primaryKeyColumns === []) {
            return $columns;
        }

        return array_map(function (Column $column) use ($primaryKeyColumns): Column {
            if ($column->notNull || !in_array($column->name, $primaryKeyColumns, true)) {
                return $column;
            }

            $notNull = new Column($column->name, $column->type, notNull: true);

            return $column->hasDefault() ? $notNull->withDefault($column->rawDefault()) : $notNull;
        }, $columns);
    }

    /** @return array{0: Column, 1: list<Constraint>} */
    private function column(string $table, ColumnDefinition $definition): array
    {
        $column = new Column($definition->name, $this->types->fromName($definition->type), $definition->notNull);

        if ($definition->default !== null) {
            $column = $column->withDefault($this->defaultValue($definition->name, $definition->default));
        }

        $constraints = [];

        if ($definition->primaryKey) {
            $constraints[] = new PrimaryKey([$definition->name]);
        }

        if ($definition->unique) {
            $constraints[] = UniqueConstraint::on($table, [$definition->name]);
        }

        if ($definition->check !== null) {
            $constraints[] = CheckConstraint::on($table, $this->printer->print($definition->check), [$definition->name]);
        }

        if ($definition->foreignKey !== null) {
            $constraints[] = $this->foreignKey($table, $definition->foreignKey);
        }

        return [$column, $constraints];
    }

    private function defaultValue(string $column, Expression $default): mixed
    {
        if ($default instanceof FunctionCall && in_array(strtoupper($default->name), self::DYNAMIC_DEFAULT_FUNCTIONS, true)) {
            throw new ExecutionException(sprintf(
                'Column "%s" cannot default to %s() yet; only literal defaults are supported.',
                $column,
                strtoupper($default->name),
            ));
        }

        return $this->evaluator->evaluate($default, new RowContext());
    }

    private function tableConstraint(string $table, TableConstraintDefinition $definition): Constraint
    {
        return match (true) {
            $definition instanceof PrimaryKeyDefinition => new PrimaryKey($definition->columns),
            $definition instanceof UniqueDefinition => $definition->name !== null
                ? new UniqueConstraint($definition->name, $definition->columns)
                : UniqueConstraint::on($table, $definition->columns),
            $definition instanceof ForeignKeyDefinition => $this->foreignKey($table, $definition),
            $definition instanceof CheckDefinition => $definition->name !== null
                ? new CheckConstraint($definition->name, $this->printer->print($definition->expression))
                : CheckConstraint::on($table, $this->printer->print($definition->expression)),
            default => throw new ExecutionException(sprintf('Unknown table constraint %s.', $definition::class)),
        };
    }

    private function foreignKey(string $table, ForeignKeyDefinition $definition): SchemaForeignKey
    {
        return $definition->name !== null
            ? new SchemaForeignKey(
                $definition->name,
                $definition->columns,
                $definition->referencedTable,
                $definition->referencedColumns,
                $definition->onDelete,
                $definition->onUpdate,
            )
            : SchemaForeignKey::on(
                $table,
                $definition->columns,
                $definition->referencedTable,
                $definition->referencedColumns,
                $definition->onDelete,
                $definition->onUpdate,
            );
    }
}

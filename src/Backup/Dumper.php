<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Backup;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PhpMiniDatabase\Schema\Constraint\Constraint;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;

/**
 * PLAN.md §11 Milestone 19's "SQL dump of schema and data" — embedded
 * only: it reads straight from `Schema\Database`/`Schema\Table`, which is
 * exactly what lets it do the one thing `Cli\Command\ExportCommand`
 * cannot (no `SHOW TABLES`, no way to ask a remote server for a table's
 * definition) — discover every table on its own and emit `CREATE TABLE`
 * for each, not only `INSERT`. See DECISIONS.md.
 *
 * `Table::type($column)->name()` is already the exact spelling
 * `Sql\Parser`'s grammar accepts back (`VARCHAR(255)`, `DECIMAL(10,2)`,
 * ...) — `TypeFactory::fromName()` round-trips it — so a column's type
 * needs no separate rendering logic here.
 *
 * `Table::indexes()` includes the index a `PRIMARY KEY`/`UNIQUE`
 * constraint automatically gets (see DECISIONS.md, Phase 6) alongside any
 * genuinely separate `CREATE INDEX` — `isConstraintIndex()` filters those
 * out before emitting `CREATE INDEX`, or the restored table would try to
 * create the same index twice.
 *
 * Tables are dumped in foreign-key dependency order (parents before
 * children) on a best-effort basis: a self-reference is not a blocker,
 * but a genuine cycle, or a reference to a table outside the dump's own
 * set, falls back to whatever order was given rather than looping forever
 * — the same honesty `Execution\Executor`'s own cascade handling already
 * has about not detecting a cycle (see DECISIONS.md, Phase 10). A
 * self-referencing table whose *rows* also point forward (a not-yet-inserted
 * manager) is not handled at all; this is a plain, single-pass dump, not
 * a two-pass insert-then-fix-up one.
 */
final class Dumper
{
    public function __construct(
        private readonly Database $database,
        private readonly Executor $executor,
    ) {
    }

    /**
     * @param list<string>|null $tables `null` dumps every table in the database
     * @param resource           $output
     */
    public function dump(mixed $output, ?array $tables = null): void
    {
        $tables = $this->orderedByDependency($tables ?? $this->database->tableNames());

        fwrite($output, sprintf("-- php-mini-database dump — %s\n\n", date('Y-m-d H:i:s')));

        foreach ($tables as $name) {
            $this->dumpTable($name, $output);
        }
    }

    /** @param resource $output */
    private function dumpTable(string $name, mixed $output): void
    {
        $table = $this->database->table($name);

        fwrite($output, $this->createTableStatement($table) . "\n\n");

        $result = $this->executor->run(sprintf('SELECT * FROM %s', $name));

        if (!$result instanceof QueryResult) {
            throw new ExecutionException(sprintf('SELECT * FROM %s did not return rows.', $name));
        }

        $rowCount = 0;

        foreach ($result->rows as $row) {
            fwrite($output, $this->insertStatement($name, $result->columns, $row) . "\n");
            $rowCount++;
        }

        if ($rowCount > 0) {
            fwrite($output, "\n");
        }

        foreach ($table->indexes() as $index) {
            if ($this->isConstraintIndex($table, $index)) {
                continue;
            }

            fwrite($output, $this->createIndexStatement($name, $index) . "\n");
        }

        fwrite($output, "\n");
    }

    private function createTableStatement(Table $table): string
    {
        $lines = array_map($this->columnDefinition(...), $table->columns());

        $primaryKey = $table->primaryKey();

        if ($primaryKey !== null) {
            $lines[] = sprintf('PRIMARY KEY (%s)', implode(', ', $primaryKey->columns()));
        }

        foreach ($table->constraints() as $constraint) {
            $clause = $this->constraintClause($constraint);

            if ($clause !== null) {
                $lines[] = $clause;
            }
        }

        return sprintf("CREATE TABLE %s (\n    %s\n);", $table->name, implode(",\n    ", $lines));
    }

    private function columnDefinition(Column $column): string
    {
        $parts = [$column->name, $column->type->name()];

        if ($column->notNull) {
            $parts[] = 'NOT NULL';
        }

        if ($column->hasDefault()) {
            $parts[] = 'DEFAULT ' . SqlLiteral::format($column->defaultValue());
        }

        return implode(' ', $parts);
    }

    private function constraintClause(Constraint $constraint): ?string
    {
        return match (true) {
            $constraint instanceof UniqueConstraint => sprintf(
                'CONSTRAINT %s UNIQUE (%s)',
                $constraint->name(),
                implode(', ', $constraint->columns()),
            ),
            $constraint instanceof ForeignKey => sprintf(
                'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                $constraint->name(),
                implode(', ', $constraint->columns()),
                $constraint->referencedTable,
                implode(', ', $constraint->referencedColumns()),
                $constraint->onDelete->value,
                $constraint->onUpdate->value,
            ),
            // CheckConstraint::$expression already carries its own
            // surrounding parens - captured verbatim from CHECK (...)'s
            // source text (see that class's own docblock) - so wrapping
            // it in another pair here would double them up.
            $constraint instanceof CheckConstraint => sprintf(
                'CONSTRAINT %s CHECK %s',
                $constraint->name(),
                $constraint->expression,
            ),
            default => null, // PrimaryKey is already emitted from Table::primaryKey() above
        };
    }

    /** @param list<string> $columns */
    private function insertStatement(string $table, array $columns, Row $row): string
    {
        $values = $row->toArray();
        $literals = array_map(static fn (string $column): string => SqlLiteral::format($values[$column]), $columns);

        return sprintf('INSERT INTO %s (%s) VALUES (%s);', $table, implode(', ', $columns), implode(', ', $literals));
    }

    private function createIndexStatement(string $table, IndexDefinition $index): string
    {
        return sprintf(
            'CREATE %sINDEX %s ON %s (%s);',
            $index->unique ? 'UNIQUE ' : '',
            $index->name,
            $table,
            implode(', ', $index->columns()),
        );
    }

    private function isConstraintIndex(Table $table, IndexDefinition $index): bool
    {
        if ($index->name === PrimaryKey::NAME) {
            return true;
        }

        foreach ($table->constraints() as $constraint) {
            if ($constraint instanceof UniqueConstraint && $constraint->name() === $index->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function orderedByDependency(array $tables): array
    {
        $remaining = $tables;
        $ordered = [];

        while ($remaining !== []) {
            $progressed = false;

            foreach ($remaining as $i => $name) {
                $dependsOn = array_diff($this->foreignKeyTargets($name), [$name]);
                $stillWaiting = array_intersect($dependsOn, $remaining);

                if ($stillWaiting === []) {
                    $ordered[] = $name;
                    unset($remaining[$i]);
                    $progressed = true;

                    break;
                }
            }

            if (!$progressed) {
                // A cycle, or a dependency outside $tables - append what
                // is left as-is rather than loop forever.
                return array_merge($ordered, array_values($remaining));
            }
        }

        return $ordered;
    }

    /** @return list<string> */
    private function foreignKeyTargets(string $tableName): array
    {
        $targets = [];

        foreach ($this->database->table($tableName)->constraints() as $constraint) {
            if ($constraint instanceof ForeignKey) {
                $targets[] = $constraint->referencedTable;
            }
        }

        return $targets;
    }
}

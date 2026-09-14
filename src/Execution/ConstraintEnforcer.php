<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Sql\Parser;

/**
 * The two constraint kinds `Schema\Table` cannot check by itself: `CHECK`
 * (needs an expression evaluator) and `FOREIGN KEY` on the *referencing*
 * side (needs another table's data). `UNIQUE`/`PRIMARY KEY` stay
 * `IndexMaintainer`'s job, and `NOT NULL` is `Table::valuesFromRow()`'s —
 * this class is only the two `Schema\Table`'s own docblock names as
 * deferred to Phase 10.
 *
 * A `ForeignKey`'s *referenced* side — what happens to a child row when the
 * parent it points at is deleted or its key changes — is not here either:
 * that needs to walk every other table looking for one that references the
 * table being written to, and to physically delete or update whatever it
 * finds, both of which are `Execution\Executor`'s job (it already owns the
 * heap/index/WAL machinery a cascade has to go through). This class only
 * ever answers "is this one row, as given, allowed to exist" — never
 * mutates anything.
 */
final readonly class ConstraintEnforcer
{
    public function __construct(
        private Database $database,
        private Evaluator $evaluator = new Evaluator(),
    ) {
    }

    /**
     * A `CHECK` constraint's stored text is parsed fresh on every call
     * rather than cached: a `CHECK` is enforced once per write, not once
     * per row of a scan, so the parse cost here is the same order as
     * everything else a single `INSERT`/`UPDATE` already does (resolving
     * defaults, checking `UNIQUE`), not a hot loop.
     *
     * @throws ConstraintViolationException when a check definitely fails —
     *                                       a `NULL` result passes, per
     *                                       standard SQL (see
     *                                       `Evaluator::isFalse()`)
     */
    public function assertCheckConstraints(Table $table, Row $row): void
    {
        $context = new RowContext($row, $table->name);

        foreach ($table->constraints() as $constraint) {
            if (!$constraint instanceof CheckConstraint) {
                continue;
            }

            $expression = Parser::parseExpression($constraint->expression);

            if ($this->evaluator->isFalse($this->evaluator->evaluate($expression, $context))) {
                throw new ConstraintViolationException(sprintf(
                    'Check constraint "%s" on "%s" failed.',
                    $constraint->name(),
                    $table->name,
                ));
            }
        }
    }

    /**
     * A `FOREIGN KEY` with any `NULL` column matches nothing and is not
     * enforced — MATCH SIMPLE, the common default across real databases,
     * and the only shape this project's `Storage\Catalog` validation (a
     * referenced side always fully covered by one `PRIMARY KEY`/`UNIQUE`
     * constraint) makes straightforward to check column by column.
     *
     * @throws ConstraintViolationException when a non-null foreign key
     *                                       value matches no row in the
     *                                       referenced table
     */
    public function assertForeignKeysOnWrite(Table $table, Row $row): void
    {
        foreach ($table->constraints() as $constraint) {
            if (!$constraint instanceof ForeignKey) {
                continue;
            }

            $values = array_map(static fn (string $column): mixed => $row->get($column), $constraint->columns());

            if (in_array(null, $values, true)) {
                continue;
            }

            if (!$this->referencedRowExists($constraint, $values)) {
                throw new ConstraintViolationException(sprintf(
                    'Foreign key "%s" on "%s" has no matching row in "%s".',
                    $constraint->name(),
                    $table->name,
                    $constraint->referencedTable,
                ));
            }
        }
    }

    /** @param list<mixed> $values in the same order as $constraint->referencedColumns() */
    private function referencedRowExists(ForeignKey $constraint, array $values): bool
    {
        $referenced = $this->database->table($constraint->referencedTable);
        $columns = $constraint->referencedColumns();

        if (count($columns) === 1) {
            $indexName = $this->singleColumnIndexNameFor($referenced, $columns[0]);

            if ($indexName !== null) {
                $index = $this->database->index($referenced->name, $indexName);

                foreach ($index->search($values[0]) as $id) {
                    return true;
                }

                return false;
            }
        }

        // No index covers this key (a composite one never gets a
        // BTreeIndex - see TableBuilder::backingIndexes()): fall back to a
        // full scan, the same trade-off a composite UNIQUE constraint
        // already makes.
        $heap = $this->database->heapFile($referenced->name);

        foreach ($heap->scan() as $record) {
            $candidate = $referenced->deserializeRow($record);

            if (array_map(static fn (string $c): mixed => $candidate->get($c), $columns) === $values) {
                return true;
            }
        }

        return false;
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
}

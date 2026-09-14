<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Storage\RecordId;

/**
 * Keeps every one of a table's indexes — `CREATE INDEX`'s explicit ones and
 * the ones `TableBuilder` backs a single-column `PRIMARY KEY`/`UNIQUE` with
 * — in step with what `Executor` writes to the heap file. Only single-
 * column indexes are considered; a composite one is recorded on the table
 * but has no `BTreeIndex` file to maintain (see DECISIONS.md).
 *
 * Uniqueness is checked *before* anything is written, not after: a
 * `BTreeIndex::insert()` failure partway through a multi-index write would
 * leave the heap row and the other indexes already changed with nothing to
 * roll either back to, since there is no WAL yet (Phase 8) to undo them
 * with. Checking first means a rejected write never touches the heap file
 * at all.
 */
final readonly class IndexMaintainer
{
    public function __construct(
        private Database $database,
    ) {
    }

    /** @throws ConstraintViolationException when a unique index would collide */
    public function assertUniqueForInsert(Table $table, Row $row): void
    {
        foreach ($this->singleColumnIndexes($table) as $definition) {
            if (!$definition->unique) {
                continue;
            }

            if ($this->firstMatch($table, $definition, $row) !== null) {
                throw new ConstraintViolationException(sprintf(
                    'Duplicate value for unique index "%s" on "%s".',
                    $definition->name,
                    $table->name,
                ));
            }
        }
    }

    /**
     * Like assertUniqueForInsert(), except a match against the row's own
     * current RecordId does not count as a collision — the row is allowed
     * to keep the value it already has.
     *
     * @throws ConstraintViolationException when a unique index would collide
     */
    public function assertUniqueForUpdate(Table $table, Row $newRow, RecordId $excluding): void
    {
        foreach ($this->singleColumnIndexes($table) as $definition) {
            if (!$definition->unique) {
                continue;
            }

            $match = $this->firstMatch($table, $definition, $newRow);

            if ($match !== null && !$match->equals($excluding)) {
                throw new ConstraintViolationException(sprintf(
                    'Duplicate value for unique index "%s" on "%s".',
                    $definition->name,
                    $table->name,
                ));
            }
        }
    }

    public function afterInsert(Table $table, Row $row, RecordId $id): void
    {
        foreach ($this->singleColumnIndexes($table) as $definition) {
            $this->database->index($table->name, $definition->name)->insert(
                $row->get($definition->columns()[0]),
                $id,
            );
        }
    }

    public function afterDelete(Table $table, Row $row, RecordId $id): void
    {
        foreach ($this->singleColumnIndexes($table) as $definition) {
            $this->database->index($table->name, $definition->name)->delete(
                $row->get($definition->columns()[0]),
                $id,
            );
        }
    }

    private function firstMatch(Table $table, IndexDefinition $definition, Row $row): ?RecordId
    {
        $value = $row->get($definition->columns()[0]);

        foreach ($this->database->index($table->name, $definition->name)->search($value) as $id) {
            return $id;
        }

        return null;
    }

    /** @return list<IndexDefinition> */
    private function singleColumnIndexes(Table $table): array
    {
        return array_values(array_filter(
            $table->indexes(),
            static fn (IndexDefinition $definition): bool => count($definition->columns()) === 1,
        ));
    }
}

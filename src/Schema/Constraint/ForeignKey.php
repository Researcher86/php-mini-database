<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;

/**
 * These columns must match a row in another table — the only constraint that
 * is about two tables rather than one.
 *
 * That is why it carries actions: deleting a parent row, or changing the key
 * a child points at, leaves the child dangling, and ON DELETE / ON UPDATE
 * say what to do about it. Enforcement is the executor's (Phase 10); what is
 * recorded here is which columns point where, and what should happen.
 *
 * The referenced columns must be unique in the parent table — otherwise
 * "the row this points at" is not a single row. The catalog checks that when
 * both tables are available, not here, because a foreign key is routinely
 * built before its parent is loaded.
 */
final readonly class ForeignKey implements Constraint
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        private string $name,
        private array $columns,
        public string $referencedTable,
        private array $referencedColumns,
        public ReferentialAction $onDelete = ReferentialAction::NO_ACTION,
        public ReferentialAction $onUpdate = ReferentialAction::NO_ACTION,
    ) {
        if ($columns === []) {
            throw new SchemaException(sprintf('Foreign key "%s" must name at least one column.', $name));
        }

        // A composite key matches column by column, in order, so the two
        // sides have to be the same length - a mismatch has no meaning to
        // fall back on.
        if (count($columns) !== count($referencedColumns)) {
            throw new SchemaException(sprintf(
                'Foreign key "%s" has %d columns but references %d.',
                $name,
                count($columns),
                count($referencedColumns),
            ));
        }
    }

    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public static function on(
        string $table,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        ReferentialAction $onDelete = ReferentialAction::NO_ACTION,
        ReferentialAction $onUpdate = ReferentialAction::NO_ACTION,
    ): self {
        return new self(
            sprintf('fk_%s_%s', $table, implode('_', $columns)),
            $columns,
            $referencedTable,
            $referencedColumns,
            $onDelete,
            $onUpdate,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<string> */
    public function referencedColumns(): array
    {
        return $this->referencedColumns;
    }
}

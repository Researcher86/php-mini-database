<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;

/**
 * No two rows may share this combination of column values.
 *
 * NULLs are the subtlety, and this database follows the SQL standard: NULL
 * is not equal to NULL, so any row with a NULL in a unique column does not
 * collide with anything, including another row identical to it. A column
 * that should reject duplicate NULLs wants NOT NULL as well.
 */
final readonly class UniqueConstraint implements Constraint
{
    /** @param list<string> $columns */
    public function __construct(
        private string $name,
        private array $columns,
    ) {
        if ($columns === []) {
            throw new SchemaException(sprintf('Unique constraint "%s" must name at least one column.', $name));
        }
    }

    /**
     * Build one with the conventional name, `uq_<table>_<columns>`, for
     * callers that have no name to give — a `UNIQUE` written inline in a
     * column definition, above all.
     *
     * @param list<string> $columns
     */
    public static function on(string $table, array $columns): self
    {
        return new self(sprintf('uq_%s_%s', $table, implode('_', $columns)), $columns);
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
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Exception\SchemaException;

/**
 * A row as the rest of the database sees it: values by column name, not by
 * position.
 *
 * Position is `Table`'s business, not the row's — a `Row` built for an
 * INSERT names only the columns given a value, in whatever order the caller
 * wrote them, and `Table::valuesFromRow()` is what turns that into the
 * positional list `RecordSerializer` needs. Keeping `Row` this simple is
 * what lets an executor pass one between operators without any of them
 * needing the table's column order in mind.
 */
final readonly class Row
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->values);
    }

    public function get(string $column): mixed
    {
        if (!$this->has($column)) {
            throw new SchemaException(sprintf('Row has no value for column "%s".', $column));
        }

        return $this->values[$column];
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_keys($this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}

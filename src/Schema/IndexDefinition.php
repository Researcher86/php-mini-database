<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Exception\SchemaException;

/**
 * An index the table maintains: a name, the columns it is built over, and
 * whether it also enforces uniqueness.
 *
 * This is deliberately not a `Constraint\`: a `UniqueConstraint` says a rule
 * about the data that happens to need an index to enforce efficiently, while
 * an `IndexDefinition` says a B-Tree exists (Phase 6) that happens to be
 * declared unique. A `PRIMARY KEY` and a plain `CREATE INDEX` both produce
 * one of these; only the former also produces a `Constraint`.
 */
final readonly class IndexDefinition
{
    /** @param list<string> $columns */
    public function __construct(
        public string $name,
        private array $columns,
        public bool $unique = false,
    ) {
        if ($columns === []) {
            throw new SchemaException(sprintf('Index "%s" must name at least one column.', $name));
        }
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }
}

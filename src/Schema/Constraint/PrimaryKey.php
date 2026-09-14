<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;

/**
 * The table's primary key: one or more columns that are together unique and
 * never NULL.
 *
 * It has no name of its own. A table has at most one primary key, so there
 * is nothing to tell apart and nothing for ALTER TABLE to disambiguate —
 * "PRIMARY" is what it is called, the same way MySQL names it.
 *
 * The NOT NULL half is not enforced here: the table checks at validation
 * time that every key column is declared NOT NULL, which keeps one rule in
 * one place instead of two that can disagree.
 */
final readonly class PrimaryKey implements Constraint
{
    public const NAME = 'PRIMARY';

    /** @param list<string> $columns */
    public function __construct(private array $columns)
    {
        if ($columns === []) {
            throw new SchemaException('A primary key must name at least one column.');
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }
}

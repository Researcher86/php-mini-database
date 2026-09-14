<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;

/**
 * An arbitrary predicate every row must satisfy: `CHECK (age >= 0)`.
 *
 * The expression is kept as the text it was written as, not as a parsed
 * tree. The schema layer is below the SQL parser — the catalog has to be
 * readable without one — and a tree would have to be serialized back to text
 * to be stored anyway. The executor parses it when it needs to evaluate it
 * (Phase 10), which is also where a syntactically broken expression is
 * caught.
 *
 * The columns the expression touches are declared separately, by whoever
 * builds the constraint, because working them out means parsing. An empty
 * list means "not known", not "touches nothing"; the table's own validation
 * checks whatever it is given and asserts nothing about what it is not.
 */
final readonly class CheckConstraint implements Constraint
{
    /** @param list<string> $columns the columns $expression refers to */
    public function __construct(
        private string $name,
        public string $expression,
        private array $columns = [],
    ) {
        if (trim($expression) === '') {
            throw new SchemaException(sprintf('Check constraint "%s" has an empty expression.', $name));
        }
    }

    /** @param list<string> $columns */
    public static function on(string $table, string $expression, array $columns = []): self
    {
        return new self(sprintf('ck_%s_%s', $table, implode('_', $columns)), $expression, $columns);
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

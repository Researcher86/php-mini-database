<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Constraint;

/**
 * A rule a table declares about its rows.
 *
 * Constraints here are *descriptions*, not checks. They say what must be
 * true; nothing in this namespace decides whether a given row makes it true.
 * That split is deliberate: NOT NULL can be checked against a row alone, but
 * UNIQUE needs an index, FOREIGN KEY needs another table, and CHECK needs an
 * expression evaluator — so enforcement lives with the executor, which has
 * all three, and this layer stays something that can be read from a JSON
 * file and written back without a running database.
 *
 * Every constraint has a name because that name is what an error message
 * quotes back to the user ("duplicate entry for key 'uq_users_email'"), and
 * because ALTER TABLE ... DROP CONSTRAINT needs something to name. Each
 * implementation carries a factory that builds the conventional name from
 * the table and columns, so the convention lives in one place per kind.
 */
interface Constraint
{
    public function name(): string;

    /**
     * The columns this constraint is about — what the table checks against
     * its own column list when it validates itself, and what an index is
     * built over for the kinds that need one.
     *
     * @return list<string>
     */
    public function columns(): array;
}

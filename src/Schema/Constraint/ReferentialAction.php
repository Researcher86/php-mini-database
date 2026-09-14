<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Constraint;

/**
 * What a foreign key does when the row it points at is deleted or its key is
 * updated.
 *
 * The names are SQL's and the values are the SQL spellings, so a schema file
 * reads as the DDL that produced it.
 */
enum ReferentialAction: string
{
    /**
     * Refuse the change while a child row still references the parent. The
     * default, and the only one that needs no extra write.
     */
    case NO_ACTION = 'NO ACTION';

    /**
     * Also refuse. Kept distinct from NO_ACTION because standard SQL defers
     * NO ACTION to the end of the statement and checks RESTRICT immediately;
     * this database checks both immediately, so today they behave alike and
     * the distinction is preserved for the schema to round-trip.
     */
    case RESTRICT = 'RESTRICT';

    /** Delete or update the child rows to follow the parent. */
    case CASCADE = 'CASCADE';

    /** Set the child's referencing columns to NULL. Needs them nullable. */
    case SET_NULL = 'SET NULL';
}

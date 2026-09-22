<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Backup;

use DateTimeImmutable;
use RuntimeException;

/**
 * A plain PHP value — whatever a `Row`/`ResultSet` value already is —
 * formatted as the SQL literal `Sql\Parser`'s expression grammar accepts
 * back. Shared between `Dumper` (embedded, schema-aware dumps) and
 * `Cli\Command\ExportCommand` (network, data-only dumps): both need to
 * turn a fetched value into `INSERT` text the same way, and a `DATETIME`
 * or an escaped quote should not have two independent implementations
 * that could quietly drift apart.
 */
final class SqlLiteral
{
    public static function format(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) || is_float($value) => (string) $value,
            $value instanceof DateTimeImmutable => "'" . $value->format('Y-m-d H:i:s') . "'",
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new RuntimeException(sprintf('Cannot format a %s as a SQL literal.', get_debug_type($value))),
        };
    }
}

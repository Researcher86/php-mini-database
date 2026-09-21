<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use DateTimeImmutable;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use RuntimeException;

/**
 * `bin/minidb export --table <name>... [--output dump.sql]` — PLAN.md
 * §9.2, scoped to what the protocol can actually support: there is no
 * `SHOW TABLES` (no SQL statement, no wire message) for this to discover
 * a database's tables on its own, so every table to export has to be
 * named explicitly with `--table` (repeatable). For the same reason there
 * is no way to ask the server for a table's *schema*, so this writes only
 * `INSERT` statements — data, not `CREATE TABLE` — for exactly the
 * columns `SELECT *` returns; the target table is expected to already
 * exist when the dump is `import`ed back in. See DECISIONS.md.
 */
final class ExportCommand
{
    /**
     * @param list<string> $tables
     * @param resource     $errorOutput
     */
    public function run(ClientConfig $config, array $tables, ?string $outputPath, mixed $errorOutput): int
    {
        if ($tables === []) {
            fwrite($errorOutput, "export requires at least one --table <name>.\n");

            return 1;
        }

        $output = $outputPath === null ? STDOUT : @fopen($outputPath, 'wb');

        if ($output === false) {
            fwrite($errorOutput, sprintf('Could not open "%s" for writing.' . "\n", $outputPath));

            return 1;
        }

        try {
            $connection = Connection::connect($config);
        } catch (ClientException $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            if ($outputPath !== null) {
                fclose($output);
            }

            return 1;
        }

        try {
            foreach ($tables as $table) {
                $this->exportTable($connection, $table, $output);
            }

            return 0;
        } catch (ClientException $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        } finally {
            $connection->close();

            if ($outputPath !== null) {
                fclose($output);
            }
        }
    }

    /** @param resource $output */
    private function exportTable(Connection $connection, string $table, mixed $output): void
    {
        $result = $connection->query(sprintf('SELECT * FROM %s', $table));
        $columns = $result->columns();

        if ($columns === []) {
            return;
        }

        $columnList = implode(', ', $columns);

        foreach ($result as $row) {
            $values = implode(', ', array_map($this->literal(...), array_values($row)));
            fwrite($output, sprintf("INSERT INTO %s (%s) VALUES (%s);\n", $table, $columnList, $values));
        }
    }

    private function literal(mixed $value): string
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

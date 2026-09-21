<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Cli\SqlSplitter;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use Throwable;

/**
 * `bin/minidb import ... dump.sql` — PLAN.md §9.2. Splits the file into
 * individual statements with `SqlSplitter` (the wire protocol's `Query`
 * only ever carries one at a time) and runs them through one connection,
 * in order, stopping at the first failure — the same "stop, do not guess
 * how to keep going" default `mysql < dump.sql` has without `--force`.
 */
final class ImportCommand
{
    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function run(ClientConfig $config, string $path, mixed $output, mixed $errorOutput): int
    {
        $sql = @file_get_contents($path);

        if ($sql === false) {
            fwrite($errorOutput, sprintf('Could not read "%s".' . "\n", $path));

            return 1;
        }

        try {
            $statements = SqlSplitter::split($sql);
        } catch (Throwable $e) {
            fwrite($errorOutput, sprintf('Could not parse "%s": %s' . "\n", $path, $e->getMessage()));

            return 1;
        }

        if ($statements === []) {
            fwrite($output, "Nothing to import - the file has no statements.\n");

            return 0;
        }

        try {
            $connection = Connection::connect($config);
        } catch (ClientException $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        try {
            foreach ($statements as $index => $statement) {
                $number = $index + 1;

                try {
                    $affected = $connection->execute($statement);
                } catch (ClientException $e) {
                    fwrite($errorOutput, sprintf(
                        "Statement %d failed: %s\n  %s\n",
                        $number,
                        $e->getMessage(),
                        $statement,
                    ));

                    return 1;
                }

                fwrite($output, sprintf(
                    "%d. OK%s\n",
                    $number,
                    $affected !== null ? sprintf(' (%d row%s affected)', $affected, $affected === 1 ? '' : 's') : '',
                ));
            }

            fwrite($output, sprintf("Imported %d statement%s.\n", count($statements), count($statements) === 1 ? '' : 's'));

            return 0;
        } finally {
            $connection->close();
        }
    }
}

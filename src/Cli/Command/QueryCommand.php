<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Cli\OutputFormat;
use PhpMiniDatabase\Cli\ResultPrinter;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ResultSet;

/**
 * `bin/minidb query ... "SQL"` — PLAN.md §9.2: one request, one
 * connection, then exit.
 *
 * `$call` rather than a SQL string, because `status` and `connections`
 * (Milestone 18) are the same command with a different request:
 * connect-or-fail, time it, print the rows, print the status line unless
 * `--quiet`, close whatever happened. They used to carry their own copy
 * of all of that in `Cli\ClientApplication`.
 */
final class QueryCommand
{
    public function __construct(
        private readonly ResultPrinter $printer = new ResultPrinter(),
    ) {
    }

    /**
     * @param callable(Connection): ResultSet $call
     * @param resource                        $output
     * @param resource                        $errorOutput
     */
    public function run(
        ClientConfig $config,
        callable $call,
        OutputFormat $format,
        bool $quiet,
        mixed $output,
        mixed $errorOutput,
    ): int {
        try {
            $connection = Connection::connect($config);
        } catch (ClientException $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        try {
            $start = microtime(true);
            $result = $call($connection);
            $elapsed = microtime(true) - $start;

            $this->printer->print($result, $format, $output);

            if (!$quiet) {
                fwrite($output, $this->printer->statusLine($result, $elapsed) . "\n");
            }

            return 0;
        } catch (ClientException $e) {
            fwrite($errorOutput, 'ERROR: ' . $e->getMessage() . "\n");

            return 1;
        } finally {
            $connection->close();
        }
    }
}

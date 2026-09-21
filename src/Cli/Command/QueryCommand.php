<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Cli\OutputFormat;
use PhpMiniDatabase\Cli\ResultPrinter;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;

/** `bin/minidb query ... "SQL"` — PLAN.md §9.2: one statement, one connection, then exit. */
final class QueryCommand
{
    public function __construct(
        private readonly ResultPrinter $printer = new ResultPrinter(),
    ) {
    }

    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function run(
        ClientConfig $config,
        string $sql,
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
            $result = $connection->query($sql);
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

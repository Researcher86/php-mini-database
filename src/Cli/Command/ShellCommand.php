<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Cli\OutputFormat;
use PhpMiniDatabase\Cli\Repl;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;

/** `bin/minidb shell` — connects, then hands off to `Repl`. */
final class ShellCommand
{
    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function run(ClientConfig $config, OutputFormat $format, bool $quiet, mixed $output, mixed $errorOutput): int
    {
        try {
            $connection = Connection::connect($config);
        } catch (ClientException $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        try {
            return (new Repl($connection, $format, $quiet, $output))->run();
        } finally {
            $connection->close();
        }
    }
}

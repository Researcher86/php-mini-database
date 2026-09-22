<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use PhpMiniDatabase\Backup\Dumper;
use PhpMiniDatabase\Backup\Restorer;
use PhpMiniDatabase\Cli\Command\ServeCommand;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use Throwable;

/**
 * `bin/minidb-server`'s dispatcher — PLAN.md §9.1's `start`, `stop`,
 * `status`, `reload`, plus `dump`/`load` this phase (Milestone 19) adds:
 * a SQL-level schema-and-data dump via `Backup\Dumper`/`Restorer`,
 * embedded — reading `Schema\Database` directly rather than through a
 * `Client\Connection`, which is exactly what lets it discover every table
 * on its own and dump `CREATE TABLE` alongside `INSERT` (`Cli\Command\ExportCommand`,
 * the network-mode equivalent, can do neither — see DECISIONS.md).
 *
 * Only `start` gets its own `Command\ServeCommand` (matching PLAN.md §4's
 * file layout, which lists no `StopCommand`/`StatusCommand`/`ReloadCommand`)
 * — `stop`/`status`/`reload`/`dump`/`load` are handled inline the same
 * way `Cli\ClientApplication::connect()` stayed inline in Phase 17 rather
 * than becoming a command class for a few lines of body.
 *
 * `stop`/`status`/`reload` never open a connection to the server they are
 * talking about — there is nothing to open one *for*: they only need to
 * know whether a pid is alive and, for `reload`, send it a signal. Both
 * are plain OS operations `PidFile` already provides.
 */
final class ServerApplication
{
    private const BOOLEAN_FLAGS = ['daemon'];

    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private readonly mixed $output = STDOUT,
        private readonly mixed $errorOutput = STDERR,
    ) {
    }

    /** @param list<string> $argv everything after the script name */
    public function run(array $argv): int
    {
        ['command' => $command, 'options' => $options, 'flags' => $flags] = ArgvParser::parse($argv, self::BOOLEAN_FLAGS);

        return match ($command) {
            'start' => (new ServeCommand())->run($options, $flags, $this->errorOutput),
            'stop' => $this->stop($options),
            'status' => $this->status($options),
            'reload' => $this->reload($options),
            'dump' => $this->dump($options),
            'load' => $this->load($options),
            default => $this->usage(),
        };
    }

    /** @param array<string, list<string>> $options */
    private function stop(array $options): int
    {
        $pidFile = $this->requirePidFile($options);

        if ($pidFile === null) {
            return 1;
        }

        $pid = $pidFile->read();

        if ($pid === null || !$pidFile->isProcessRunning()) {
            fwrite($this->errorOutput, "Not running.\n");

            return 1;
        }

        posix_kill($pid, SIGTERM);

        for ($i = 0; $i < 100 && $pidFile->isProcessRunning(); $i++) {
            usleep(50_000);
        }

        if ($pidFile->isProcessRunning()) {
            fwrite($this->errorOutput, sprintf("Timed out waiting for pid %d to stop.\n", $pid));

            return 1;
        }

        $pidFile->remove();
        fwrite($this->output, sprintf("Stopped (pid %d).\n", $pid));

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private function status(array $options): int
    {
        $pidFile = $this->requirePidFile($options);

        if ($pidFile === null) {
            return 1;
        }

        if ($pidFile->isProcessRunning()) {
            fwrite($this->output, sprintf("Running (pid %d).\n", $pidFile->read()));

            return 0;
        }

        fwrite($this->output, "Not running.\n");

        return 1;
    }

    /** @param array<string, list<string>> $options */
    private function reload(array $options): int
    {
        $pidFile = $this->requirePidFile($options);

        if ($pidFile === null) {
            return 1;
        }

        $pid = $pidFile->read();

        if ($pid === null || !$pidFile->isProcessRunning()) {
            fwrite($this->errorOutput, "Not running.\n");

            return 1;
        }

        posix_kill($pid, SIGHUP);
        fwrite($this->output, sprintf("Reload signal sent (pid %d).\n", $pid));

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private function requirePidFile(array $options): ?PidFile
    {
        $path = $options['pid-file'][0] ?? null;

        if ($path === null) {
            fwrite($this->errorOutput, "requires --pid-file <path>.\n");

            return null;
        }

        return new PidFile($path);
    }

    /** @param array<string, list<string>> $options */
    private function dump(array $options): int
    {
        $dataDirectory = $options['data'][0] ?? null;

        if ($dataDirectory === null) {
            fwrite($this->errorOutput, "dump requires --data <dir>.\n");

            return 1;
        }

        $outputPath = $options['output'][0] ?? null;
        $output = $outputPath === null ? $this->output : @fopen($outputPath, 'wb');

        if ($output === false) {
            fwrite($this->errorOutput, sprintf('Could not open "%s" for writing.' . "\n", $outputPath));

            return 1;
        }

        try {
            $database = Database::open($dataDirectory);
        } catch (Throwable $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            if ($outputPath !== null) {
                fclose($output);
            }

            return 1;
        }

        try {
            $executor = new Executor($database);
            (new Dumper($database, $executor))->dump($output, $options['table'] ?? null);

            return 0;
        } catch (Throwable $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            return 1;
        } finally {
            $database->close();

            if ($outputPath !== null) {
                fclose($output);
            }
        }
    }

    /** @param array<string, list<string>> $options */
    private function load(array $options): int
    {
        $dataDirectory = $options['data'][0] ?? null;
        $inputPath = $options['input'][0] ?? null;

        if ($dataDirectory === null || $inputPath === null) {
            fwrite($this->errorOutput, "load requires --data <dir> and --input <file>.\n");

            return 1;
        }

        $sql = @file_get_contents($inputPath);

        if ($sql === false) {
            fwrite($this->errorOutput, sprintf('Could not read "%s".' . "\n", $inputPath));

            return 1;
        }

        try {
            $database = Database::open($dataDirectory);
        } catch (Throwable $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        try {
            $count = (new Restorer(new Executor($database)))->restore($sql);
            fwrite($this->output, sprintf("Loaded %d statement%s.\n", $count, $count === 1 ? '' : 's'));

            return 0;
        } catch (Throwable $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            return 1;
        } finally {
            $database->close();
        }
    }

    private function usage(): int
    {
        fwrite($this->errorOutput, "Usage: minidb-server <start|stop|status|reload|dump|load> ...\n");

        return 1;
    }
}

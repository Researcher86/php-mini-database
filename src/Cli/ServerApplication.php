<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use PhpMiniDatabase\Cli\Command\ServeCommand;

/**
 * `bin/minidb-server`'s dispatcher — PLAN.md §9.1's `start`, `stop`,
 * `status`, `reload`. Only `start` gets its own `Command\ServeCommand`
 * (matching PLAN.md §4's file layout, which lists no `StopCommand`/
 * `StatusCommand`/`ReloadCommand`) — the other three are a `PidFile`
 * read and one `posix_kill()` call each, handled inline the same way
 * `Cli\ClientApplication::connect()` stayed inline in Phase 17 rather
 * than becoming an eighth command class for a few lines of body.
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

    private function usage(): int
    {
        fwrite($this->errorOutput, "Usage: minidb-server <start|stop|status|reload> ...\n");

        return 1;
    }
}

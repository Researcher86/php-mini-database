<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use PhpMiniDatabase\Cli\PidFile;
use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Infrastructure\LogLevel;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use RuntimeException;

/**
 * `bin/minidb-server start` — PLAN.md §9.1/§7.2/§7.3's `CLI flags > ENV >
 * defaults` (minus the `config/server.php` layer: no milestone's checklist
 * ever claims loading one, so it stays a named, unbuilt gap rather than
 * scope this phase invented for itself — see DECISIONS.md).
 *
 * `--daemon` forks (`pcntl_fork()`, already required elsewhere in this
 * project — PLAN.md §2.2 already notes the Windows limitation that comes
 * with it), the parent exits immediately, and the child calls
 * `posix_setsid()` to detach from the controlling terminal before closing
 * `STDIN`/`STDOUT`/`STDERR` — the standard, minimal Unix daemonizing
 * recipe. It refuses outright, rather than daemonizing into silence, if
 * `--log-file`/`MINIDB_LOG_FILE` still points at a terminal stream: once
 * detached, nothing would ever be able to read what `Logger` writes there
 * again.
 */
final class ServeCommand
{
    private const TERMINAL_STREAMS = ['php://stdout', 'php://stderr', 'STDOUT', 'STDERR'];

    /**
     * @param array<string, list<string>> $options
     * @param array<string, bool>         $flags
     * @param resource                    $errorOutput
     */
    public function run(array $options, array $flags, mixed $errorOutput): int
    {
        $daemonize = $flags['daemon'] ?? false;
        $logFile = $this->resolve($options, 'log-file', 'MINIDB_LOG_FILE', 'php://stderr');

        if ($daemonize && in_array($logFile, self::TERMINAL_STREAMS, true)) {
            fwrite($errorOutput, "--daemon requires --log-file (or MINIDB_LOG_FILE) pointing at a real file: "
                . "a daemon detached from the terminal cannot log to it.\n");

            return 1;
        }

        $pidFilePath = $options['pid-file'][0] ?? null;
        $pidFile = $pidFilePath !== null ? new PidFile($pidFilePath) : null;

        if ($pidFile !== null && $pidFile->isProcessRunning()) {
            fwrite($errorOutput, sprintf("Already running (pid %d) - see %s.\n", $pidFile->read(), $pidFilePath));

            return 1;
        }

        if ($daemonize) {
            try {
                $this->daemonize();
            } catch (RuntimeException $e) {
                fwrite($errorOutput, $e->getMessage() . "\n");

                return 1;
            }
        }

        // After daemonize(), never before: the parent exits immediately,
        // so a pid written above the fork would name a process that no
        // longer exists - and stop/status/reload have nothing but this
        // file to go on.
        $pidFile?->write((int) getmypid());

        $logLevel = LogLevel::tryFrom(strtolower($this->resolve($options, 'log-level', 'MINIDB_LOG_LEVEL', 'info'))) ?? LogLevel::INFO;
        $server = new Server($this->buildConfig($options), new Logger($logFile, $logLevel));

        try {
            $server->start();
            $server->run();

            return 0;
        } finally {
            $pidFile?->remove();
        }
    }

    /** @param array<string, list<string>> $options */
    private function buildConfig(array $options): ServerConfig
    {
        return new ServerConfig(
            dataDirectory: $this->resolve($options, 'data', 'MINIDB_DATA', getcwd() . '/data/mydb'),
            host: $this->resolve($options, 'host', 'MINIDB_HOST', '127.0.0.1'),
            port: (int) $this->resolve($options, 'port', 'MINIDB_PORT', '5433'),
            maxConnections: (int) $this->resolve($options, 'max-connections', 'MINIDB_MAX_CONNECTIONS', '100'),
            authEnabled: $this->resolveBool($this->resolve($options, 'auth-enabled', 'MINIDB_AUTH_ENABLED', 'false')),
        );
    }

    /** @param array<string, list<string>> $options */
    private function resolve(array $options, string $key, string $envVar, string $default): string
    {
        return $options[$key][0] ?? (getenv($envVar) ?: $default);
    }

    private function resolveBool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    private function daemonize(): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork to daemonize.');
        }

        if ($pid > 0) {
            exit(0);
        }

        if (posix_setsid() === -1) {
            throw new RuntimeException('Could not detach from the controlling terminal.');
        }

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
    }
}

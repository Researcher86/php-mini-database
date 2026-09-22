<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use InvalidArgumentException;
use PhpMiniDatabase\Cli\Command\BackupCommand;
use PhpMiniDatabase\Cli\Command\ExportCommand;
use PhpMiniDatabase\Cli\Command\ImportCommand;
use PhpMiniDatabase\Cli\Command\QueryCommand;
use PhpMiniDatabase\Cli\Command\RestoreCommand;
use PhpMiniDatabase\Cli\Command\ShellCommand;
use PhpMiniDatabase\Cli\Command\UserCommand;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ResultSet;

/**
 * `bin/minidb`'s dispatcher — PLAN.md §9.2's `connect`, `query`, `shell`,
 * `import`, `export`, `user`, `backup`, `restore` subcommands, plus three
 * this phase (Milestone 18) adds beyond that list: `status`,
 * `connections`, `kill <id>` — one-shot, scriptable access to `SHOW_STATUS`/
 * `SHOW_CONNECTIONS`/`KILL`, the typed messages `Cli\Repl` recognizes from
 * plain text instead (`Cli\AdminCommand`) for the interactive shell. Real
 * subcommands here, not SQL-shaped text, for the same reason `user`/
 * `import`/`export` already are.
 *
 * `connect` has no `Command\*` class of its own (unlike every other
 * subcommand here) — PLAN.md §4's file layout does not list one either,
 * and there is little to it beyond opening a `Connection` and reporting
 * whether that worked, which is handled inline instead of promoted to an
 * eighth command class for a one-line body. `status`/`connections`/`kill`
 * stay inline for the same reason.
 *
 * `--json`/`--csv`/`--vertical`/`--quiet` (PLAN.md §9.4) are read once,
 * globally, here — not per-statement inside `Repl` (there is no `\G`-style
 * per-statement format switch in this project).
 *
 * `$output`/`$errorOutput` default to `STDOUT`/`STDERR` for real use
 * (`bin/minidb`), but are constructor-injected rather than hardcoded, so
 * `ClientApplicationTest` can capture what a run actually printed from a
 * `php://memory` stream instead of needing to fork a process to read the
 * real file descriptors back.
 */
final class ClientApplication
{
    private const BOOLEAN_FLAGS = ['json', 'csv', 'vertical', 'quiet', 'force'];

    private readonly ResultPrinter $printer;

    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private readonly mixed $output = STDOUT,
        private readonly mixed $errorOutput = STDERR,
    ) {
        $this->printer = new ResultPrinter();
    }

    /** @param list<string> $argv everything after the script name */
    public function run(array $argv): int
    {
        ['command' => $command, 'args' => $args, 'options' => $options, 'flags' => $flags]
            = ArgvParser::parse($argv, self::BOOLEAN_FLAGS);

        try {
            $format = OutputFormat::fromFlags($flags);
        } catch (InvalidArgumentException $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        $quiet = $flags['quiet'] ?? false;
        $config = $this->buildConfig($options);

        return match ($command) {
            'connect' => $this->connect($config, $quiet),
            'query' => $this->query($config, $args, $format, $quiet),
            'shell' => (new ShellCommand())->run($config, $format, $quiet, $this->output, $this->errorOutput),
            'import' => $this->import($config, $args),
            'export' => (new ExportCommand())->run($config, $options['table'] ?? [], $options['output'][0] ?? null, $this->errorOutput),
            'user' => (new UserCommand())->run($args[0] ?? null, array_slice($args, 1), $options, $this->output, $this->errorOutput),
            'status' => $this->runRequest($config, $format, $quiet, static fn (Connection $c): ResultSet => $c->showStatus()),
            'connections' => $this->runRequest($config, $format, $quiet, static fn (Connection $c): ResultSet => $c->showConnections()),
            'kill' => $this->kill($config, $args),
            'backup' => (new BackupCommand())->run($options, $this->output, $this->errorOutput),
            'restore' => (new RestoreCommand())->run($options, $flags, $this->output, $this->errorOutput),
            default => $this->usage(),
        };
    }

    /** @param array<string, list<string>> $options */
    private function buildConfig(array $options): ClientConfig
    {
        return new ClientConfig(
            host: $options['host'][0] ?? '127.0.0.1',
            port: isset($options['port'][0]) ? (int) $options['port'][0] : 5433,
            user: $options['user'][0] ?? '',
            password: $options['password'][0] ?? '',
        );
    }

    private function connect(ClientConfig $config, bool $quiet): int
    {
        try {
            $connection = Connection::connect($config);
        } catch (ClientException $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        if (!$quiet) {
            fwrite($this->output, sprintf(
                "Connected to %s:%d%s.\n",
                $config->host,
                $config->port,
                $config->user !== '' ? " as {$config->user}" : '',
            ));
        }

        $connection->close();

        return 0;
    }

    /** @param list<string> $args */
    private function query(ClientConfig $config, array $args, OutputFormat $format, bool $quiet): int
    {
        $sql = $args[0] ?? null;

        if ($sql === null) {
            fwrite($this->errorOutput, "query requires a SQL statement.\n");

            return 1;
        }

        return $this->runRequest($config, $format, $quiet, static fn (Connection $c): ResultSet => $c->query($sql));
    }

    /** @param list<string> $args */
    private function import(ClientConfig $config, array $args): int
    {
        $path = $args[0] ?? null;

        if ($path === null) {
            fwrite($this->errorOutput, "import requires a file path.\n");

            return 1;
        }

        return (new ImportCommand())->run($config, $path, $this->output, $this->errorOutput);
    }

    /** @param callable(Connection): ResultSet $call */
    private function runRequest(ClientConfig $config, OutputFormat $format, bool $quiet, callable $call): int
    {
        return (new QueryCommand($this->printer))->run($config, $call, $format, $quiet, $this->output, $this->errorOutput);
    }

    /** @param list<string> $args */
    private function kill(ClientConfig $config, array $args): int
    {
        $id = $args[0] ?? null;

        if ($id === null || !ctype_digit($id)) {
            fwrite($this->errorOutput, "kill requires a numeric connection id.\n");

            return 1;
        }

        try {
            $connection = Connection::connect($config);
        } catch (ClientException $e) {
            fwrite($this->errorOutput, $e->getMessage() . "\n");

            return 1;
        }

        try {
            $connection->kill((int) $id);
            fwrite($this->output, "Connection {$id} killed.\n");

            return 0;
        } catch (ClientException $e) {
            fwrite($this->errorOutput, 'ERROR: ' . $e->getMessage() . "\n");

            return 1;
        } finally {
            $connection->close();
        }
    }

    private function usage(): int
    {
        fwrite($this->errorOutput, "Usage: minidb <connect|query|shell|import|export|user|status|connections|kill|backup|restore> ...\n");

        return 1;
    }
}

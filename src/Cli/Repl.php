<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use Closure;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Sql\Lexer;
use PhpMiniDatabase\Sql\TokenType;
use Throwable;

/**
 * `bin/minidb shell` — PLAN.md §9.3's interactive prompt: read a
 * statement (possibly across several lines), run it, print it, repeat.
 *
 * History and autocompletion (PLAN.md §9.2's REPL checklist) come from
 * `ext-readline` when it is loaded — `readline()`/`readline_add_history()`
 * for the former, `readline_completion_function()` seeded with every
 * keyword `Sql\TokenType::keywords()` already knows (the single source of
 * truth the lexer itself uses, not a second hand-copied list) for the
 * latter. Neither table nor column names can be offered: nothing in this
 * protocol lets a client ask the server what tables exist (no `SHOW
 * TABLES`, no equivalent wire message), so completion is keywords only.
 * Without `ext-readline` at all, input falls back to plain `fgets(STDIN)`
 * with neither feature — a real but graceful degradation, not a hard
 * requirement this class enforces.
 *
 * `$readLine` is an injected seam, not a hardcoded call to `readline()`:
 * it is what lets `ReplTest` drive this class with scripted input and
 * capture its output deterministically, without a real TTY.
 *
 * A buffered statement is "complete" once tokenizing it succeeds *and*
 * its last real token is a `;` — an unterminated string or a `CREATE
 * TABLE` still missing its closing `)` both fail to tokenize cleanly (or
 * simply do not end in `;` yet), which this reads as "read another line"
 * rather than an error, the same as a real SQL shell's multi-line input.
 * One buffered line may still hold more than one statement
 * (`SELECT 1; SELECT 2;`) — `SqlSplitter` is what splits it, the same
 * class `Command\ImportCommand` uses for a dump file. Each resulting
 * statement is checked against `AdminCommand::parse()` first — PLAN.md
 * §10.4's `SHOW STATUS`/`SHOW CONNECTIONS`/`KILL <id>`, recognized as
 * plain text rather than real SQL grammar (see that class's own
 * docblock) and dispatched to `Client\Connection`'s matching typed-message
 * method instead of `query()`.
 *
 * A `ClientException` with `$errorCode !== null` is a normal failed
 * statement (bad SQL, a constraint violation) - reported, and the REPL
 * keeps going. One with `$errorCode === null` is a connection-level
 * failure (the socket died, a timeout) - reported, and the REPL stops,
 * since there is nothing left to run further statements against.
 */
final class Repl
{
    private const PRIMARY_PROMPT = 'minidb> ';

    private const CONTINUATION_PROMPT = '     -> ';

    private const EXIT_WORDS = ['exit', 'quit'];

    private readonly Closure $readLine;

    private readonly ResultPrinter $printer;

    /** @param resource $output */
    public function __construct(
        private readonly Connection $connection,
        private readonly OutputFormat $format,
        private readonly bool $quiet,
        private mixed $output,
        ?Closure $readLine = null,
    ) {
        $this->printer = new ResultPrinter();
        $this->readLine = $readLine ?? self::defaultReadLine();
    }

    public function run(): int
    {
        $this->installAutocompletion();

        if (!$this->quiet) {
            fwrite($this->output, "Type SQL ending with ';', or 'exit'/'quit' to leave.\n");
        }

        $buffer = '';

        while (true) {
            $line = ($this->readLine)($buffer === '' ? self::PRIMARY_PROMPT : self::CONTINUATION_PROMPT);

            if ($line === false) {
                fwrite($this->output, "\n");

                return 0;
            }

            if ($buffer === '' && in_array(strtolower(trim($line)), self::EXIT_WORDS, true)) {
                return 0;
            }

            if (function_exists('readline_add_history') && trim($line) !== '') {
                readline_add_history($line);
            }

            $buffer = $buffer === '' ? $line : $buffer . "\n" . $line;

            if (!$this->isComplete($buffer)) {
                continue;
            }

            if (!$this->runBuffered($buffer)) {
                return 1;
            }

            $buffer = '';
        }
    }

    private function isComplete(string $buffer): bool
    {
        try {
            $tokens = Lexer::tokenize($buffer);
        } catch (Throwable) {
            return false;
        }

        $lastReal = null;

        foreach ($tokens as $token) {
            if (!$token->is(TokenType::EOF)) {
                $lastReal = $token;
            }
        }

        return $lastReal !== null && $lastReal->is(TokenType::SEMICOLON);
    }

    /** Runs every statement found in `$buffer`. Returns `false` if the REPL should stop. */
    private function runBuffered(string $buffer): bool
    {
        foreach (SqlSplitter::split($buffer) as $statement) {
            if (!$this->runOne($statement)) {
                return false;
            }
        }

        return true;
    }

    /** Runs one statement. Returns `false` if the REPL should stop. */
    private function runOne(string $sql): bool
    {
        $admin = AdminCommand::parse($sql);

        try {
            $start = microtime(true);

            if ($admin?->kind === AdminCommandKind::KILL) {
                $this->connection->kill($admin->connectionId);

                if (!$this->quiet) {
                    fwrite($this->output, sprintf("Connection %d killed.\n", $admin->connectionId));
                }

                return true;
            }

            $result = match ($admin?->kind) {
                AdminCommandKind::SHOW_STATUS => $this->connection->showStatus(),
                AdminCommandKind::SHOW_CONNECTIONS => $this->connection->showConnections(),
                null => $this->connection->query($sql),
            };
            $elapsed = microtime(true) - $start;

            $this->printer->print($result, $this->format, $this->output);

            if (!$this->quiet) {
                fwrite($this->output, $this->printer->statusLine($result, $elapsed) . "\n");
            }

            return true;
        } catch (ClientException $e) {
            fwrite($this->output, 'ERROR: ' . $e->getMessage() . "\n");

            return $e->errorCode !== null;
        }
    }

    private function installAutocompletion(): void
    {
        if (!function_exists('readline_completion_function')) {
            return;
        }

        $keywords = array_keys(TokenType::keywords());

        readline_completion_function(static function (string $input) use ($keywords): array {
            if ($input === '') {
                return $keywords;
            }

            $upper = strtoupper($input);

            return array_values(array_filter(
                $keywords,
                static fn (string $keyword): bool => str_starts_with($keyword, $upper),
            ));
        });
    }

    private static function defaultReadLine(): Closure
    {
        return static function (string $prompt): string|false {
            if (function_exists('readline')) {
                return readline($prompt);
            }

            fwrite(STDOUT, $prompt);
            $line = fgets(STDIN);

            return $line === false ? false : rtrim($line, "\n");
        };
    }
}

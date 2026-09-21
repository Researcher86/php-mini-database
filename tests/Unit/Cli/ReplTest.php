<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use Closure;
use PhpMiniDatabase\Cli\OutputFormat;
use PhpMiniDatabase\Cli\Repl;
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `Repl` against a real `bin/minidb-server` child process, with scripted
 * input fed through the injected `$readLine` closure instead of a real
 * TTY — see `Repl`'s own docblock for why that seam exists.
 */
final class ReplTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15541;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->startServer($this->path('mydb'), self::PORT);
        $this->connection = Connection::connect(new ClientConfig(port: self::PORT));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->stopServer();
        $this->tearDownTemporaryDirectory();
    }

    /** @param list<string> $lines */
    private function scriptedReadLine(array $lines): Closure
    {
        $lines[] = false;

        return static function () use (&$lines): string|false {
            return array_shift($lines);
        };
    }

    /** @return resource */
    private function stream(): mixed
    {
        return fopen('php://memory', 'r+');
    }

    private function contents(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function testASingleStatementIsRunAndPrinted(): void
    {
        $output = $this->stream();
        $repl = new Repl(
            $this->connection,
            OutputFormat::TABLE,
            false,
            $output,
            $this->scriptedReadLine(['SELECT 1 + 1 AS two;']),
        );

        $exitCode = $repl->run();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('| two |', $this->contents($output));
        self::assertStringContainsString('1 row in set', $this->contents($output));
    }

    public function testAStatementSpanningMultipleLinesIsAccumulatedUntilComplete(): void
    {
        $output = $this->stream();
        $repl = new Repl(
            $this->connection,
            OutputFormat::TABLE,
            true,
            $output,
            $this->scriptedReadLine(['CREATE TABLE users (', '  id INT PRIMARY KEY', ');']),
        );

        $exitCode = $repl->run();

        self::assertSame(0, $exitCode);

        $verify = $this->connection->query('SELECT id FROM users');
        self::assertSame(['id'], $verify->columns());
    }

    public function testTwoStatementsOnOneLineBothRun(): void
    {
        $output = $this->stream();
        $repl = new Repl(
            $this->connection,
            OutputFormat::TABLE,
            true,
            $output,
            $this->scriptedReadLine(['SELECT 1 AS a; SELECT 2 AS b;']),
        );

        $repl->run();

        $text = $this->contents($output);
        self::assertStringContainsString('| a |', $text);
        self::assertStringContainsString('| b |', $text);
    }

    public function testExitLeavesTheLoopWithoutRunningAnything(): void
    {
        $output = $this->stream();
        $repl = new Repl($this->connection, OutputFormat::TABLE, true, $output, $this->scriptedReadLine(['exit']));

        $exitCode = $repl->run();

        self::assertSame(0, $exitCode);
        self::assertSame('', $this->contents($output));
    }

    public function testEndOfInputLeavesTheLoopCleanly(): void
    {
        $output = $this->stream();
        $repl = new Repl($this->connection, OutputFormat::TABLE, true, $output, $this->scriptedReadLine([]));

        $exitCode = $repl->run();

        self::assertSame(0, $exitCode);
    }

    public function testAFailingStatementIsReportedAndTheReplKeepsGoing(): void
    {
        $output = $this->stream();
        $repl = new Repl(
            $this->connection,
            OutputFormat::TABLE,
            true,
            $output,
            $this->scriptedReadLine(['SELECT * FROM no_such_table;', 'SELECT 1 AS ok;']),
        );

        $exitCode = $repl->run();

        self::assertSame(0, $exitCode);
        $text = $this->contents($output);
        self::assertStringContainsString('ERROR', $text);
        self::assertStringContainsString('| ok |', $text);
    }

    public function testConnectionLossStopsTheRepl(): void
    {
        $output = $this->stream();
        $this->connection->close();

        $repl = new Repl(
            $this->connection,
            OutputFormat::TABLE,
            true,
            $output,
            $this->scriptedReadLine(['SELECT 1;']),
        );

        $exitCode = $repl->run();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ERROR', $this->contents($output));
    }
}

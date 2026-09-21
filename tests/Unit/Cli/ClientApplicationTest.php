<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\ClientApplication;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `bin/minidb`'s subcommands end to end, against a real `bin/minidb-server`
 * child process (see `RunningServer`'s own docblock for why) — `STDOUT`/
 * `STDERR` are swapped for `php://memory` streams via `ClientApplication`'s
 * constructor, so a run's output can be asserted on directly.
 */
final class ClientApplicationTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15540;

    /** @var resource */
    private mixed $output;

    /** @var resource */
    private mixed $errorOutput;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->startServer($this->path('mydb'), self::PORT);
        $this->output = fopen('php://memory', 'r+');
        $this->errorOutput = fopen('php://memory', 'r+');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->tearDownTemporaryDirectory();
        fclose($this->output);
        fclose($this->errorOutput);
    }

    /** @param list<string> $argv */
    private function runCli(array $argv): int
    {
        return (new ClientApplication($this->output, $this->errorOutput))->run($argv);
    }

    private function outputText(): string
    {
        rewind($this->output);

        return stream_get_contents($this->output);
    }

    private function errorText(): string
    {
        rewind($this->errorOutput);

        return stream_get_contents($this->errorOutput);
    }

    public function testConnectSucceeds(): void
    {
        $exitCode = $this->runCli(['connect', '--port', (string) self::PORT]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Connected to 127.0.0.1:' . self::PORT, $this->outputText());
    }

    public function testConnectToTheWrongPortFails(): void
    {
        $exitCode = $this->runCli(['connect', '--port', (string) (self::PORT + 1), '--host', '127.0.0.1']);

        self::assertSame(1, $exitCode);
        self::assertNotSame('', $this->errorText());
    }

    public function testQueryPrintsATableAndAStatusLine(): void
    {
        $exitCode = $this->runCli(['query', '--port', (string) self::PORT, 'SELECT 1 + 1 AS two']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('| two |', $this->outputText());
        self::assertStringContainsString('row in set', $this->outputText());
    }

    public function testQueryWithoutSqlFails(): void
    {
        $exitCode = $this->runCli(['query', '--port', (string) self::PORT]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires a SQL statement', $this->errorText());
    }

    public function testQueryOfInvalidSqlReportsTheError(): void
    {
        $exitCode = $this->runCli(['query', '--port', (string) self::PORT, 'SELECT * FROM no_such_table']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ERROR', $this->errorText());
    }

    public function testJsonFlagChangesTheOutputFormat(): void
    {
        $exitCode = $this->runCli(['query', '--port', (string) self::PORT, '--json', '--quiet', 'SELECT 1 AS one']);

        self::assertSame(0, $exitCode);
        self::assertSame([['one' => 1]], json_decode($this->outputText(), true));
    }

    public function testTwoFormatFlagsTogetherFailsBeforeConnecting(): void
    {
        $exitCode = $this->runCli(['query', '--port', (string) self::PORT, '--json', '--csv', 'SELECT 1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Only one output format flag', $this->errorText());
    }

    public function testImportRunsEveryStatementInTheFile(): void
    {
        $path = $this->path('dump.sql');
        file_put_contents($path, "CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50));\n"
            . "INSERT INTO users (id, name) VALUES (1, 'Ann'), (2, 'Bob');\n");

        $exitCode = $this->runCli(['import', '--port', (string) self::PORT, $path]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Imported 2 statements.', $this->outputText());

        $verify = $this->runCli(['query', '--port', (string) self::PORT, '--json', '--quiet', 'SELECT id, name FROM users ORDER BY id']);
        self::assertSame(0, $verify);
    }

    public function testImportStopsAtTheFirstFailingStatement(): void
    {
        $path = $this->path('dump.sql');
        file_put_contents($path, "CREATE TABLE users (id INT PRIMARY KEY);\n"
            . "INSERT INTO no_such_table (id) VALUES (1);\n"
            . "CREATE TABLE never_reached (id INT PRIMARY KEY);\n");

        $exitCode = $this->runCli(['import', '--port', (string) self::PORT, $path]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Statement 2 failed', $this->errorText());
    }

    public function testImportOfAMissingFileFails(): void
    {
        $exitCode = $this->runCli(['import', '--port', (string) self::PORT, $this->path('missing.sql')]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not read', $this->errorText());
    }

    public function testExportWritesInsertStatementsForTheNamedTable(): void
    {
        $this->runCli(['query', '--port', (string) self::PORT, 'CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))']);
        $this->runCli(['query', '--port', (string) self::PORT, "INSERT INTO users (id, name) VALUES (1, 'Ann')"]);

        $outputPath = $this->path('export.sql');
        $exitCode = $this->runCli(['export', '--port', (string) self::PORT, '--table', 'users', '--output', $outputPath]);

        self::assertSame(0, $exitCode);
        self::assertSame("INSERT INTO users (id, name) VALUES (1, 'Ann');\n", file_get_contents($outputPath));
    }

    public function testExportWithoutATableFails(): void
    {
        $exitCode = $this->runCli(['export', '--port', (string) self::PORT]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires at least one --table', $this->errorText());
    }

    public function testExportedDataCanBeReimportedAfterEmptyingTheTable(): void
    {
        $this->runCli(['query', '--port', (string) self::PORT, 'CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))']);
        $this->runCli(['query', '--port', (string) self::PORT, "INSERT INTO users (id, name) VALUES (1, 'Ann'), (2, 'O''Brien')"]);

        $dumpPath = $this->path('roundtrip.sql');
        $this->runCli(['export', '--port', (string) self::PORT, '--table', 'users', '--output', $dumpPath]);

        $this->runCli(['query', '--port', (string) self::PORT, 'DELETE FROM users']);

        $exitCode = $this->runCli(['import', '--port', (string) self::PORT, $dumpPath]);
        self::assertSame(0, $exitCode);

        $this->output = fopen('php://memory', 'r+');
        $this->runCli(['query', '--port', (string) self::PORT, '--json', '--quiet', 'SELECT name FROM users WHERE id = 2']);
        self::assertSame([['name' => "O'Brien"]], json_decode($this->outputText(), true));
    }

    public function testUserAddAndList(): void
    {
        $dataDirectory = $this->path('mydb');

        $add = $this->runCli(['user', 'add', 'alice', '--password', 'secret', '--data', $dataDirectory]);
        self::assertSame(0, $add);

        $this->output = fopen('php://memory', 'r+');
        $list = $this->runCli(['user', 'list', '--data', $dataDirectory]);
        self::assertSame(0, $list);
        self::assertSame("alice\n", $this->outputText());
    }

    public function testUserWithoutDataFlagFails(): void
    {
        $exitCode = $this->runCli(['user', 'list']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires --data', $this->errorText());
    }

    public function testBackupAndRestoreAreHonestStubs(): void
    {
        self::assertSame(1, $this->runCli(['backup']));
        self::assertStringContainsString('Milestone 19', $this->errorText());

        $this->errorOutput = fopen('php://memory', 'r+');
        self::assertSame(1, $this->runCli(['restore']));
        self::assertStringContainsString('Milestone 19', $this->errorText());
    }

    public function testAnUnknownCommandPrintsUsage(): void
    {
        $exitCode = $this->runCli(['bogus']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Usage: minidb', $this->errorText());
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\ServerApplication;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\MemoryStream;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `bin/minidb-server dump`/`load` — unlike `start`/`stop`/`status`/
 * `reload` (`ServerApplicationTest`), neither touches a socket, a
 * signal or a fork: both are a plain `Schema\Database` operation, so
 * this drives `ServerApplication::run()` directly, in process, no real
 * child process needed.
 */
final class ServerApplicationDumpTest extends TestCase
{
    use TemporaryDirectory;
    use MemoryStream;

    /** @var resource */
    private mixed $output;

    /** @var resource */
    private mixed $errorOutput;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->output = $this->memoryStream();
        $this->errorOutput = $this->memoryStream();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        fclose($this->output);
        fclose($this->errorOutput);
    }

    private function app(): ServerApplication
    {
        return new ServerApplication($this->output, $this->errorOutput);
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

    private function seedDatabase(string $dataDirectory): void
    {
        $database = Database::open($dataDirectory);
        $executor = new Executor($database);
        $executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $database->close();
    }

    public function testDumpWritesSchemaAndDataToTheGivenOutputFile(): void
    {
        $dataDirectory = $this->path('mydb');
        $this->seedDatabase($dataDirectory);

        $outputPath = $this->path('dump.sql');
        $exitCode = $this->app()->run(['dump', '--data', $dataDirectory, '--output', $outputPath]);

        self::assertSame(0, $exitCode);
        $sql = file_get_contents($outputPath);
        self::assertNotFalse($sql);
        self::assertStringContainsString('CREATE TABLE users', $sql);
        self::assertStringContainsString("INSERT INTO users (id, name) VALUES (1, 'Ann');", $sql);
    }

    public function testDumpWithoutAnOutputFlagWritesToStdout(): void
    {
        $dataDirectory = $this->path('mydb');
        $this->seedDatabase($dataDirectory);

        $exitCode = $this->app()->run(['dump', '--data', $dataDirectory]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('CREATE TABLE users', $this->outputText());
    }

    public function testDumpWithAnExplicitTableListDumpsOnlyThatTable(): void
    {
        $dataDirectory = $this->path('mydb');
        $database = Database::open($dataDirectory);
        $executor = new Executor($database);
        $executor->run('CREATE TABLE a (id INT PRIMARY KEY)');
        $executor->run('CREATE TABLE b (id INT PRIMARY KEY)');
        $database->close();

        $exitCode = $this->app()->run(['dump', '--data', $dataDirectory, '--table', 'b']);

        self::assertSame(0, $exitCode);
        $text = $this->outputText();
        self::assertStringNotContainsString('CREATE TABLE a', $text);
        self::assertStringContainsString('CREATE TABLE b', $text);
    }

    public function testDumpWithoutDataFlagFails(): void
    {
        $exitCode = $this->app()->run(['dump']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires --data', $this->errorText());
    }

    public function testLoadRestoresIntoAFreshDataDirectory(): void
    {
        $sourceDirectory = $this->path('source');
        $this->seedDatabase($sourceDirectory);
        $dumpPath = $this->path('dump.sql');
        $this->app()->run(['dump', '--data', $sourceDirectory, '--output', $dumpPath]);

        $targetDirectory = $this->path('target');
        $exitCode = $this->app()->run(['load', '--data', $targetDirectory, '--input', $dumpPath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Loaded', $this->outputText());

        $restored = Database::open($targetDirectory);
        self::assertTrue($restored->hasTable('users'));
        $restored->close();
    }

    public function testLoadWithoutRequiredFlagsFails(): void
    {
        $exitCode = $this->app()->run(['load', '--data', $this->path('mydb')]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires --data', $this->errorText());
    }

    public function testLoadOfAMissingFileFails(): void
    {
        $exitCode = $this->app()->run(['load', '--data', $this->path('mydb'), '--input', $this->path('missing.sql')]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not read', $this->errorText());
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\PidFile;
use PhpMiniDatabase\Cli\ServerApplication;
use PhpMiniDatabase\Tests\Support\MemoryStream;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `bin/minidb-server`'s `start`/`stop`/`status`/`reload` lifecycle,
 * against real child processes (`start`/`--daemon` genuinely fork real
 * OS processes - there is no meaningful way to test a pid file and
 * `posix_kill()` against anything else).
 *
 * Every process this file spawns is tracked the moment its pid is known
 * (`$processes` for a `proc_open()` handle, `$stragglers` for a detached
 * daemon's bare pid, no longer reachable through the handle that started
 * it) - before any assertion that could fail and skip the rest of the
 * test method, so `tearDown()` can always find and kill it regardless of
 * where a test failed. A test that leaves its server listening leaks the
 * port to every test that runs after it, turning one real failure into a
 * cascade of unrelated ones.
 */
final class ServerApplicationTest extends TestCase
{
    use TemporaryDirectory;
    use MemoryStream;

    private const PORT = 15560;

    /** @var list<resource> */
    private array $processes = [];

    /** @var list<int> */
    private array $stragglers = [];

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
        foreach ($this->processes as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }

            proc_close($process);
        }

        foreach ($this->stragglers as $pid) {
            if (!function_exists('posix_kill')) {
                continue;
            }

            @posix_kill($pid, SIGKILL);

            // Every test in this class reuses the same fixed port -
            // waiting here for the kill to actually take effect (SIGKILL
            // delivery is asynchronous) keeps a straggler this test
            // leaves behind from still holding that port open for
            // whichever test runs next.
            for ($i = 0; $i < 50 && @posix_kill($pid, 0); $i++) {
                usleep(20_000);
            }
        }

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

    /**
     * @param list<string>           $args     everything after "bin/minidb-server"
     * @param array<string, string>  $extraEnv
     *
     * @return array{0: resource, 1: resource, 2: resource} the process, its stdout pipe, its stderr pipe
     */
    private function spawn(array $args, array $extraEnv = []): array
    {
        $binary = dirname(__DIR__, 3) . '/bin/minidb-server';
        $env = array_merge(['MINIDB_LOG_LEVEL' => 'error'], $extraEnv);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(['php', $binary, ...$args], $descriptors, $pipes, dirname(__DIR__, 3), $env);

        if ($process === false) {
            throw new RuntimeException('Could not start bin/minidb-server.');
        }

        $this->processes[] = $process;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    /**
     * Starts `bin/minidb-server start ...` and waits until it is actually
     * listening on `self::PORT`.
     *
     * @param list<string>          $extraArgs
     * @param array<string, string> $extraEnv
     *
     * @return resource the launching process
     */
    private function launch(array $extraArgs, array $extraEnv = []): mixed
    {
        $env = array_merge([
            'MINIDB_DATA' => $this->path('mydb'),
            'MINIDB_PORT' => (string) self::PORT,
            'MINIDB_HOST' => '127.0.0.1',
        ], $extraEnv);

        [$process, , $stderr] = $this->spawn(['start', ...$extraArgs], $env);

        // --daemon's launching process is *expected* to exit quickly (the
        // parent forks and exits right away, by design) - only a
        // non-daemon launcher exiting early is a sign something crashed.
        $daemonizing = in_array('--daemon', $extraArgs, true);

        for ($i = 0; $i < 150; $i++) {
            if (!$daemonizing && !proc_get_status($process)['running']) {
                throw new RuntimeException('bin/minidb-server exited before it started listening: ' . stream_get_contents($stderr));
            }

            $probe = @stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errorCode, $errorMessage, 0.05);

            if ($probe !== false) {
                fclose($probe);

                return $process;
            }

            usleep(20_000);
        }

        throw new RuntimeException('bin/minidb-server never started listening.');
    }

    /** @param resource $process */
    private function waitForExit(mixed $process, int $maxIterations = 100): void
    {
        for ($i = 0; $i < $maxIterations && proc_get_status($process)['running']; $i++) {
            usleep(20_000);
        }
    }

    public function testStatusBeforeStartReportsNotRunning(): void
    {
        $exitCode = $this->app()->run(['status', '--pid-file', $this->path('server.pid')]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Not running', $this->outputText());
    }

    public function testStartWritesAPidFileAndStatusSeesIt(): void
    {
        $pidFilePath = $this->path('server.pid');
        $this->launch(['--pid-file', $pidFilePath]);

        $pidFile = new PidFile($pidFilePath);
        $pid = $pidFile->read();
        self::assertNotNull($pid);
        $this->stragglers[] = $pid;
        self::assertTrue($pidFile->isProcessRunning());

        $exitCode = $this->app()->run(['status', '--pid-file', $pidFilePath]);
        self::assertSame(0, $exitCode);
        self::assertStringContainsString("Running (pid {$pid})", $this->outputText());
    }

    public function testStartRefusesWhenAlreadyRunning(): void
    {
        $pidFilePath = $this->path('server.pid');
        $this->stragglers[] = $this->pidOfLaunch($this->launch(['--pid-file', $pidFilePath]));

        [$second, , $secondStderr] = $this->spawn(['start', '--pid-file', $pidFilePath], [
            'MINIDB_DATA' => $this->path('mydb2'),
            'MINIDB_PORT' => (string) (self::PORT + 1),
        ]);

        $this->waitForExit($second, 20);

        self::assertFalse(proc_get_status($second)['running']);
        self::assertSame(1, proc_get_status($second)['exitcode']);
        self::assertStringContainsString('Already running', stream_get_contents($secondStderr));
    }

    public function testStartRecoversFromAStalePidFile(): void
    {
        $pidFilePath = $this->path('server.pid');
        // A pid essentially guaranteed not to be a running process in this
        // test container.
        (new PidFile($pidFilePath))->write(999999);

        $this->launch(['--pid-file', $pidFilePath]);

        $pidFile = new PidFile($pidFilePath);
        $pid = $pidFile->read();
        self::assertNotSame(999999, $pid);
        self::assertNotNull($pid);
        $this->stragglers[] = $pid;
        self::assertTrue($pidFile->isProcessRunning());
    }

    public function testStopTerminatesTheProcessAndRemovesThePidFile(): void
    {
        $pidFilePath = $this->path('server.pid');
        $process = $this->launch(['--pid-file', $pidFilePath]);
        $pid = (new PidFile($pidFilePath))->read();
        self::assertNotNull($pid);
        $this->stragglers[] = $pid;

        $exitCode = $this->app()->run(['stop', '--pid-file', $pidFilePath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("Stopped (pid {$pid})", $this->outputText());
        self::assertFileDoesNotExist($pidFilePath);
        self::assertFalse((new PidFile($pidFilePath))->isProcessRunning());

        $this->waitForExit($process, 50);
        self::assertFalse(proc_get_status($process)['running']);
    }

    public function testStopWithoutAPidFileFails(): void
    {
        $exitCode = $this->app()->run(['stop']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('requires --pid-file', $this->errorText());
    }

    public function testStopWhenNotRunningFails(): void
    {
        $exitCode = $this->app()->run(['stop', '--pid-file', $this->path('server.pid')]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Not running', $this->errorText());
    }

    public function testReloadSendsSighupWithoutStoppingTheServer(): void
    {
        $pidFilePath = $this->path('server.pid');
        $this->launch(['--pid-file', $pidFilePath]);
        $pid = (new PidFile($pidFilePath))->read();
        self::assertNotNull($pid);
        $this->stragglers[] = $pid;

        $exitCode = $this->app()->run(['reload', '--pid-file', $pidFilePath]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("Reload signal sent (pid {$pid})", $this->outputText());

        // Still running, still answering queries, after the signal.
        self::assertTrue((new PidFile($pidFilePath))->isProcessRunning());
        $probe = @stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($probe, (string) $errorMessage);
        fclose($probe);
    }

    public function testDaemonRequiresARealLogFile(): void
    {
        [$process, , $stderr] = $this->spawn(['start', '--daemon'], [
            'MINIDB_DATA' => $this->path('mydb'),
            'MINIDB_PORT' => (string) self::PORT,
        ]);

        $this->waitForExit($process, 20);

        self::assertFalse(proc_get_status($process)['running']);
        self::assertSame(1, proc_get_status($process)['exitcode']);
        self::assertStringContainsString('requires --log-file', stream_get_contents($stderr));
    }

    public function testDaemonDetachesAndTheServerKeepsRunning(): void
    {
        $pidFilePath = $this->path('server.pid');
        $logFilePath = $this->path('server.log');

        // Unlike every other test here, this one actually checks the log
        // file's content - "Listening on ..." is an INFO message, so the
        // class-wide MINIDB_LOG_LEVEL=error default (see spawn()) has to
        // be raised back to "info" here, or Logger::log() filters it out
        // and the file is never even created.
        $launcher = $this->launch(
            ['--daemon', '--pid-file', $pidFilePath, '--log-file', $logFilePath],
            ['MINIDB_LOG_LEVEL' => 'info'],
        );

        // The launching process is a short-lived parent that forks and
        // exits immediately once daemonized - it is not the server.
        $this->waitForExit($launcher);
        self::assertFalse(proc_get_status($launcher)['running']);

        $pidFile = new PidFile($pidFilePath);
        $daemonPid = $pidFile->read();
        self::assertNotNull($daemonPid);
        $this->stragglers[] = $daemonPid;
        self::assertTrue($pidFile->isProcessRunning());

        $probe = @stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($probe, (string) $errorMessage);
        fclose($probe);

        self::assertStringContainsString('Listening on', (string) file_get_contents($logFilePath));

        $exitCode = $this->app()->run(['stop', '--pid-file', $pidFilePath]);
        self::assertSame(0, $exitCode);
    }

    /** @param resource $process */
    private function pidOfLaunch(mixed $process): int
    {
        return proc_get_status($process)['pid'];
    }
}

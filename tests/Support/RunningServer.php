<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Support;

use RuntimeException;

/**
 * A real `bin/minidb-server`, running as a genuine child process — what
 * `Client\Connection` tests need that `Network\Server`'s own tests
 * (`ServerTest` and friends) do not: those drive `Server::tick()` by hand,
 * *from* the test method, which only works because the test is also the
 * one making the client calls. `Client\Connection` is a normal blocking
 * API from its caller's point of view — `$conn->query(...)` is one call
 * that waits for its own reply — so testing it honestly needs something
 * actually running concurrently to answer, not a `tick()` this same
 * method could also call. A real child process is the actual thing a
 * `Connection` is built to talk to, not a stand-in for it. See
 * DECISIONS.md.
 *
 * One fixed port per caller (not `port: 0`): unlike `ServerConfig`, the
 * CLI entrypoint has nothing that reports back which port it bound to
 * once `MINIDB_PORT=0` asks the OS to pick one, so a test picks its own
 * distinct, unlikely-to-collide port instead and `start()` polls until
 * something is actually listening on it.
 */
trait RunningServer
{
    /** @var resource|null */
    private mixed $serverProcess = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    /**
     * @param array<string, string> $extraEnv
     * @param list<string>          $extraArgs appended after the `start` subcommand
     */
    protected function startServer(string $dataDirectory, int $port, array $extraEnv = [], array $extraArgs = []): void
    {
        $binary = dirname(__DIR__, 2) . '/bin/minidb-server';

        $env = array_merge([
            'MINIDB_HOST' => '127.0.0.1',
            'MINIDB_PORT' => (string) $port,
            'MINIDB_DATA' => $dataDirectory,
            'MINIDB_LOG_LEVEL' => 'error',
        ], $extraEnv);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['php', $binary, 'start', ...$extraArgs], $descriptors, $pipes, dirname(__DIR__, 2), $env);

        if ($process === false) {
            throw new RuntimeException('Could not start bin/minidb-server.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->serverProcess = $process;
        $this->serverPipes = $pipes;

        $this->waitUntilListening('127.0.0.1', $port);
    }

    protected function stopServer(): void
    {
        if ($this->serverProcess === null) {
            return;
        }

        proc_terminate($this->serverProcess);

        for ($i = 0; $i < 50; $i++) {
            $status = proc_get_status($this->serverProcess);

            if (!$status['running']) {
                break;
            }

            usleep(20_000);
        }

        if (proc_get_status($this->serverProcess)['running']) {
            proc_terminate($this->serverProcess, SIGKILL);
        }

        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($this->serverProcess);
        $this->serverProcess = null;
        $this->serverPipes = [];
    }

    private function waitUntilListening(string $host, int $port): void
    {
        for ($i = 0; $i < 150; $i++) {
            $status = proc_get_status($this->serverProcess);

            if (!$status['running']) {
                throw new RuntimeException(sprintf(
                    "bin/minidb-server exited before it started listening.\nstderr: %s",
                    stream_get_contents($this->serverPipes[2]),
                ));
            }

            $probe = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage, 0.05);

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException(sprintf('bin/minidb-server never started listening on %s:%d.', $host, $port));
    }
}

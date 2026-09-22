<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Milestone 20's "concurrency tests (many clients)": real, OS-level
 * concurrency against one live `bin/minidb-server`, not several
 * `Client\Connection`s taking turns from a single test process (what
 * `ServerClientTest` already covers). `pcntl_fork()` gives each worker its
 * own process and its own socket, hitting the server's event loop the way
 * genuinely simultaneous clients actually would.
 */
final class ConcurrencyTest extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15571;
    private const WORKERS = 6;
    private const ROWS_PER_WORKER = 25;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available in this environment.');
        }

        $this->setUpTemporaryDirectory();
        $this->startServer($this->path('mydb'), self::PORT);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->tearDownTemporaryDirectory();
    }

    private function connect(): Connection
    {
        return Connection::connect(new ClientConfig(port: self::PORT, connectTimeoutSeconds: 2.0, readTimeoutSeconds: 5.0));
    }

    public function testManyRealClientProcessesWritingConcurrentlyLoseNoRows(): void
    {
        $setup = $this->connect();
        $setup->execute('CREATE TABLE counters (id INT PRIMARY KEY, worker INT NOT NULL)');
        $setup->close();

        $pids = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                exit($this->runWorker($worker));
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), "Worker process {$pid} did not exit cleanly.");
        }

        $verify = $this->connect();
        $total = $verify->query('SELECT COUNT(*) AS n FROM counters')->fetch();
        self::assertSame(['n' => self::WORKERS * self::ROWS_PER_WORKER], $total);

        foreach (range(0, self::WORKERS - 1) as $worker) {
            $count = $verify->query('SELECT COUNT(*) AS n FROM counters WHERE worker = ?', [$worker])->fetch();
            self::assertSame(['n' => self::ROWS_PER_WORKER], $count, "Worker {$worker} lost or gained rows.");
        }

        $verify->close();
    }

    /** A forked child's exit code: 0 for success, 1 for any failure - assertions cannot cross the fork. */
    private function runWorker(int $worker): int
    {
        try {
            $conn = $this->connect();

            for ($i = 0; $i < self::ROWS_PER_WORKER; $i++) {
                $id = $worker * 1000 + $i;
                $conn->execute('INSERT INTO counters (id, worker) VALUES (?, ?)', [$id, $worker]);
            }

            $conn->close();

            return 0;
        } catch (Throwable) {
            return 1;
        }
    }

    public function testManySimultaneouslyOpenConnectionsKeepIndependentPreparedStatementIds(): void
    {
        $connections = [];

        for ($i = 0; $i < 8; $i++) {
            $connections[] = $this->connect();
        }

        $connections[0]->execute('CREATE TABLE t (id INT PRIMARY KEY)');
        $connections[0]->execute('INSERT INTO t (id) VALUES (1), (2), (3)');

        // Every connection prepares the *same* SQL, so each server-side
        // session hands out its own statement id starting from the same
        // point - proving one connection's Execute never reaches another's
        // prepared statement.
        $statements = array_map(static fn (Connection $c) => $c->prepare('SELECT id FROM t WHERE id = ?'), $connections);

        foreach ($statements as $i => $stmt) {
            $result = $stmt->execute([($i % 3) + 1]);
            self::assertSame([['id' => ($i % 3) + 1]], $result->fetchAll());
        }

        foreach ($connections as $connection) {
            $connection->close();
        }
    }
}

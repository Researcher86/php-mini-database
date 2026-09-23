<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Embedded mode from several OS processes at once — a PHP-FPM pool or a
 * fleet of queue workers that skips `bin/minidb-server` and opens the data
 * directory directly. `ConcurrencyTest` covers the same pressure through a
 * server; this covers what happens without one.
 *
 * The property under test is not "every process gets in" but "nothing is
 * written and then silently gone": before `Database::open()` took the
 * directory lock, each process kept its own `PageManager` page count,
 * handed out page numbers the others were already using, and wrote whole
 * pages back over rows another process had just put there — 180 inserts
 * that every caller saw succeed, 100 rows on disk, no error anywhere.
 */
final class EmbeddedConcurrencyTest extends TestCase
{
    use TemporaryDirectory;

    private const WORKERS = 4;
    private const ROWS_PER_WORKER = 15;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available in this environment.');
        }

        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testConcurrentProcessesOnOneDataDirectoryNeverLoseAConfirmedInsert(): void
    {
        $dataDirectory = $this->path('mydb');
        $database = Database::open($dataDirectory);
        (new Executor($database))->run('CREATE TABLE orders (id INT PRIMARY KEY, worker INT NOT NULL)');
        $database->close();

        $pids = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                // The exit code carries the count back: assertions cannot
                // cross the fork, and the parent needs to know how many
                // inserts this child was told had succeeded.
                exit($this->runWorker($dataDirectory, $worker));
            }

            $pids[] = $pid;
        }

        $confirmed = 0;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $confirmed += pcntl_wexitstatus($status);
        }

        $verify = Database::open($dataDirectory);
        $result = (new Executor($verify))->run('SELECT COUNT(*) AS n FROM orders');
        self::assertInstanceOf(QueryResult::class, $result);
        $rows = iterator_to_array($result->rows);
        $verify->close();

        self::assertSame($confirmed, $rows[0]->get('n'), 'An insert a process was told had succeeded is missing from the table.');
    }

    /** @return int how many inserts this worker was told had succeeded */
    private function runWorker(string $dataDirectory, int $worker): int
    {
        $confirmed = 0;

        try {
            $database = Database::open($dataDirectory);
            $executor = new Executor($database);

            for ($i = 0; $i < self::ROWS_PER_WORKER; $i++) {
                $executor->run('INSERT INTO orders (id, worker) VALUES (?, ?)', [$worker * 1000 + $i, $worker]);
                $confirmed++;
            }

            $database->close();
        } catch (Throwable) {
            // A worker that cannot get the directory lock, or loses it
            // partway, reports only what it was actually told succeeded -
            // which is the whole point: being refused is fine, being told
            // "written" about a row that is not there is not.
        }

        return $confirmed;
    }
}

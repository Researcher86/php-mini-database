<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Benchmark;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Tests\Support\RunningServer;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "load tests (network benchmark)" - the one bullet the
 * checklist names explicitly. See `InsertBench`'s docblock for why this
 * lives outside `composer test`. Against a real `bin/minidb-server` child
 * process, this measures what an embedded benchmark structurally cannot:
 * the round-trip cost the wire protocol and TCP itself add on top of the
 * same engine `InsertBench`/`SelectBench` already measure without either.
 */
final class NetworkBench extends TestCase
{
    use TemporaryDirectory;
    use RunningServer;

    private const PORT = 15581;
    private const ROUND_TRIPS = 500;

    protected function setUp(): void
    {
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

    public function testSimpleQueryRoundTripLatency(): void
    {
        $conn = $this->connect();

        $start = microtime(true);

        for ($i = 0; $i < self::ROUND_TRIPS; $i++) {
            $conn->query('SELECT 1 AS ok');
        }

        $elapsed = microtime(true) - $start;
        $this->report('SELECT 1 round trip', self::ROUND_TRIPS, $elapsed, minPerSecond: 50.0);

        $conn->close();
    }

    public function testInsertRoundTripLatency(): void
    {
        $conn = $this->connect();
        $conn->execute('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');

        $start = microtime(true);

        for ($i = 0; $i < self::ROUND_TRIPS; $i++) {
            $conn->execute('INSERT INTO t (id, n) VALUES (?, ?)', [$i, $i]);
        }

        $elapsed = microtime(true) - $start;
        $this->report('INSERT round trip', self::ROUND_TRIPS, $elapsed, minPerSecond: 30.0);

        $conn->close();
    }

    public function testPreparedStatementRoundTripLatencyBeatsReparsingEveryTime(): void
    {
        $conn = $this->connect();
        $conn->execute('CREATE TABLE t (id INT PRIMARY KEY, n INT NOT NULL)');
        $conn->execute('INSERT INTO t (id, n) VALUES (1, 1)');

        $stmt = $conn->prepare('SELECT n FROM t WHERE id = ?');

        $start = microtime(true);

        for ($i = 0; $i < self::ROUND_TRIPS; $i++) {
            $stmt->execute([1]);
        }

        $elapsed = microtime(true) - $start;
        $this->report('prepared EXECUTE round trip', self::ROUND_TRIPS, $elapsed, minPerSecond: 50.0);

        $conn->close();
    }

    private function report(string $label, int $roundTrips, float $elapsedSeconds, float $minPerSecond): void
    {
        $perSecond = $elapsedSeconds > 0 ? $roundTrips / $elapsedSeconds : $roundTrips;
        fwrite(STDOUT, sprintf(
            "  [NetworkBench] %-35s %6d round trips in %7.3fs  (%7.0f/s, %6.2fms avg)\n",
            $label,
            $roundTrips,
            $elapsedSeconds,
            $perSecond,
            ($elapsedSeconds / $roundTrips) * 1000,
        ));

        self::assertGreaterThan($minPerSecond, $perSecond, "{$label} dropped below {$minPerSecond}/s - investigate for a regression.");
    }
}

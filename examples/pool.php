<?php

declare(strict_types=1);

/**
 * `Client\ConnectionPool` - PLAN.md §8.3's fixed-size pool of
 * `Client\Connection`s, for a program that talks to the server from
 * several places without opening (and re-authenticating) a fresh
 * connection every time. Start a server first, same as `client.php`:
 *
 *   php bin/minidb-server start --data /tmp/minidb-example --port 5433
 *
 * Then run this script: php examples/pool.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\ConnectionPool;

$pool = new ConnectionPool(new ClientConfig(host: '127.0.0.1', port: 5433), maxConnections: 4);

try {
    $setup = $pool->acquire();
    $setup->execute('CREATE TABLE IF NOT EXISTS jobs (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL)');
    $setup->execute('DELETE FROM jobs');
    $pool->release($setup);
} catch (ClientException $e) {
    fwrite(STDERR, "Could not connect: {$e->getMessage()}\n");
    fwrite(STDERR, "Start a server first: php bin/minidb-server start --data /tmp/minidb-example --port 5433\n");

    exit(1);
}

/**
 * A handful of independent units of work, each acquiring its own
 * connection and releasing it when done - exactly the pattern a
 * request-handling worker pool uses one connection per job for.
 */
function processJob(ConnectionPool $pool, int $id): void
{
    $connection = $pool->acquire();

    try {
        $connection->execute('INSERT INTO jobs (id, status) VALUES (?, ?)', [$id, 'done']);
    } finally {
        // Always released, even if the job's own query fails - a failed
        // connection is repaired by acquire()'s own isAlive() check on
        // its NEXT use, not here.
        $pool->release($connection);
    }
}

for ($id = 1; $id <= 5; $id++) {
    processJob($pool, $id);
}

$reader = $pool->acquire();
$count = $reader->query('SELECT COUNT(*) AS n FROM jobs')->fetch()['n'] ?? 0;
echo "Processed 5 jobs across a pool of at most 4 connections; {$count} total rows now in jobs.\n";
$pool->release($reader);

$pool->close();

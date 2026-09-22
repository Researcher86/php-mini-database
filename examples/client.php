<?php

declare(strict_types=1);

/**
 * Talking to a running `bin/minidb-server` over the network, one plain
 * `Client\Connection` at a time - the PLAN.md §8.2 API. Start a server
 * first:
 *
 *   php bin/minidb-server start --data /tmp/minidb-example --port 5433
 *
 * Then run this script: php examples/client.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;

$config = new ClientConfig(host: '127.0.0.1', port: 5433);

try {
    $connection = Connection::connect($config);
} catch (ClientException $e) {
    fwrite(STDERR, "Could not connect: {$e->getMessage()}\n");
    fwrite(STDERR, "Start a server first: php bin/minidb-server start --data /tmp/minidb-example --port 5433\n");

    exit(1);
}

$connection->execute('CREATE TABLE IF NOT EXISTS visits (id INT PRIMARY KEY, path VARCHAR(200) NOT NULL)');

$nextId = ($connection->query('SELECT MAX(id) AS max_id FROM visits')->fetch()['max_id'] ?? 0) + 1;
$connection->execute('INSERT INTO visits (id, path) VALUES (?, ?)', [$nextId, '/example/' . $nextId]);

echo "Recorded visit #{$nextId}. All visits so far:\n";

foreach ($connection->query('SELECT id, path FROM visits ORDER BY id')->fetchAll() as $row) {
    printf("  #%d %s\n", $row['id'], $row['path']);
}

// A statement, prepared once and re-executed with different parameters -
// PLAN.md §8.2's PREPARE/EXECUTE, without re-parsing the SQL each time.
$countByPrefix = $connection->prepare("SELECT COUNT(*) AS n FROM visits WHERE path LIKE ?");
$exampleVisits = $countByPrefix->execute(['/example/%'])->fetch()['n'] ?? 0;
echo "Visits under /example/: {$exampleVisits}\n";
$countByPrefix->close();

$connection->close();

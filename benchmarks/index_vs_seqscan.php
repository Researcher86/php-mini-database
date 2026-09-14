<?php

declare(strict_types=1);

/**
 * How much a B-Tree index actually buys over a sequential scan.
 *
 * Builds one table, fills it with rows, then times a point lookup by an
 * indexed column against the same lookup forced through SeqScan (by asking
 * for a column the table has no index on, `id2`, which holds the exact same
 * values as the indexed `id`). The two queries return identical rows; only
 * the access path differs. Usage:
 *
 *   php benchmarks/index_vs_seqscan.php [row_count]
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Schema\Database;

$rows = (int) ($argv[1] ?? 5_000);

$dataDir = sys_get_temp_dir() . '/minidb-bench-' . bin2hex(random_bytes(8));
$database = Database::open($dataDir);
$executor = new Executor($database);

$executor->run('CREATE TABLE bench (id INT PRIMARY KEY, id2 INT, payload VARCHAR(100))');

printf("Inserting %d rows...\n", $rows);
$insertStart = microtime(true);

for ($i = 0; $i < $rows; $i++) {
    $executor->run('INSERT INTO bench (id, id2, payload) VALUES (?, ?, ?)', [$i, $i, str_repeat('x', 100)]);
}

printf("Insert: %.3fs (%.0f rows/s)\n\n", microtime(true) - $insertStart, $rows / (microtime(true) - $insertStart));

$target = intdiv($rows, 2);
$lookups = 200;

$indexedStart = microtime(true);
for ($i = 0; $i < $lookups; $i++) {
    $result = $executor->run('SELECT * FROM bench WHERE id = ?', [$target]);
    iterator_to_array($result->rows, false);
}
$indexedTime = microtime(true) - $indexedStart;

$seqScanStart = microtime(true);
for ($i = 0; $i < $lookups; $i++) {
    $result = $executor->run('SELECT * FROM bench WHERE id2 = ?', [$target]);
    iterator_to_array($result->rows, false);
}
$seqScanTime = microtime(true) - $seqScanStart;

printf("%d point lookups, %d rows in the table:\n", $lookups, $rows);
printf("  IndexScan (id):   %.4fs total, %.4fms/lookup\n", $indexedTime, $indexedTime / $lookups * 1000);
printf("  SeqScan   (id2):  %.4fs total, %.4fms/lookup\n", $seqScanTime, $seqScanTime / $lookups * 1000);
printf("  Speedup:          %.1fx\n", $seqScanTime / max($indexedTime, 0.000001));

$database->close();
(new FileSystem())->removeDirectory($dataDir);

<?php

declare(strict_types=1);

/**
 * Using php-mini-database embedded, in the same process - no server, no
 * network, just `Schema\Database` and `Execution\Executor` opened directly
 * against a data directory. This is what `bin/minidb-server` itself does
 * under the hood; a script that only ever needs one process talking to
 * its own data can skip the server entirely.
 *
 * Run: php examples/embedded.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;

$dataDirectory = sys_get_temp_dir() . '/minidb-example-embedded-' . uniqid();
$database = Database::open($dataDirectory);
$executor = new Executor($database);

$executor->run('CREATE TABLE todos (id INT PRIMARY KEY, title VARCHAR(100) NOT NULL, done INT NOT NULL DEFAULT 0)');

$executor->run('INSERT INTO todos (id, title) VALUES (?, ?)', [1, 'Write the docs']);
$executor->run('INSERT INTO todos (id, title) VALUES (?, ?)', [2, 'Ship the release']);
$executor->run('UPDATE todos SET done = 1 WHERE id = ?', [1]);

$result = $executor->run('SELECT id, title, done FROM todos ORDER BY id');

if (!$result instanceof QueryResult) {
    throw new RuntimeException('Expected a QueryResult from a SELECT.');
}

foreach ($result->rows as $row) {
    /** @var Row $row */
    printf("#%d %-20s %s\n", $row->get('id'), $row->get('title'), $row->get('done') ? 'done' : 'pending');
}

$database->close();

// The data directory persists on disk; a real program keeps it. This
// example cleans up after itself so running it twice never collides.
removeDirectoryRecursively($dataDirectory);

function removeDirectoryRecursively(string $directory): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

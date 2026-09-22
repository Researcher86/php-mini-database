<?php

declare(strict_types=1);

/**
 * `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` from the client side - PLAN.md
 * §8's transaction API, moving money between two accounts as the classic
 * example of "several statements that must all succeed or all fail
 * together." Start a server first, same as `client.php`:
 *
 *   php bin/minidb-server start --data /tmp/minidb-example --port 5433
 *
 * Then run this script: php examples/transaction.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;

try {
    $connection = Connection::connect(new ClientConfig(host: '127.0.0.1', port: 5433));
} catch (ClientException $e) {
    fwrite(STDERR, "Could not connect: {$e->getMessage()}\n");
    fwrite(STDERR, "Start a server first: php bin/minidb-server start --data /tmp/minidb-example --port 5433\n");

    exit(1);
}

$connection->execute(
    'CREATE TABLE IF NOT EXISTS accounts (id INT PRIMARY KEY, balance INT NOT NULL CHECK (balance >= 0))',
);
$connection->execute('DELETE FROM accounts');
$connection->execute('INSERT INTO accounts (id, balance) VALUES (1, 100), (2, 20)');

function transfer(Connection $connection, int $fromAccount, int $toAccount, int $amount): void
{
    $connection->beginTransaction();

    try {
        $connection->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [$amount, $fromAccount]);
        $connection->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [$amount, $toAccount]);
        $connection->commit();
    } catch (ClientException $e) {
        // A CHECK violation (balance would go negative), a lost
        // connection, anything at all - roll the whole transfer back
        // rather than leaving one leg of it applied.
        $connection->rollback();

        throw $e;
    }
}

transfer($connection, fromAccount: 1, toAccount: 2, amount: 30);
echo "Transferred 30 from account 1 to account 2.\n";

try {
    // Account 1 now has 70; this would take it to -30, which CHECK
    // (balance >= 0) forbids - the whole transfer rolls back, and
    // account 2's balance is left exactly where it was.
    transfer($connection, fromAccount: 1, toAccount: 2, amount: 100);
} catch (ClientException $e) {
    echo "Second transfer correctly rejected: {$e->getMessage()}\n";
}

// SAVEPOINT lets part of a transaction be undone without losing the rest.
$connection->beginTransaction();
$connection->execute('UPDATE accounts SET balance = balance + 1000 WHERE id = 1');
$connection->savepoint('before_risky_bonus');
$connection->execute('UPDATE accounts SET balance = balance + 999999 WHERE id = 2');
$connection->rollback(); // Discards everything, savepoint included, for this example's final state.

foreach ($connection->query('SELECT id, balance FROM accounts ORDER BY id')->fetchAll() as $row) {
    printf("Account #%d: balance %d\n", $row['id'], $row['balance']);
}

$connection->close();

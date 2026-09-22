<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Backup;

use PhpMiniDatabase\Backup\Dumper;
use PhpMiniDatabase\Backup\Restorer;
use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * PLAN.md §11 Milestone 19's own "Roundtrip tests" bullet: `Dumper`'s
 * output fed straight into `Restorer`, against a schema exercising every
 * constraint kind `Backup\Dumper::constraintClause()` knows how to
 * render, plus a genuine secondary index and a value needing its quote
 * escaped — proving the two classes actually agree with each other, not
 * only with their own unit tests.
 */
final class DumpRestoreRoundTripTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testASchemaWithEveryConstraintKindSurvivesADumpAndRestore(): void
    {
        $source = Database::open($this->path('source'));
        $sourceExecutor = new Executor($source);

        $sourceExecutor->run(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE, age INT DEFAULT 0 CHECK (age >= 0))',
        );
        $sourceExecutor->run('CREATE INDEX idx_users_age ON users (age)');
        $sourceExecutor->run(
            "INSERT INTO users (id, email, age) VALUES (1, 'ann@example.com', 30), (2, 'o''brien@example.com', 0)",
        );
        $sourceExecutor->run(
            'CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total DECIMAL(10,2), '
            . 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)',
        );
        $sourceExecutor->run("INSERT INTO orders (id, user_id, total) VALUES (1, 1, 99.50)");

        $stream = fopen('php://memory', 'r+');
        (new Dumper($source, $sourceExecutor))->dump($stream);
        rewind($stream);
        $sql = stream_get_contents($stream);
        $source->close();

        $target = Database::open($this->path('target'));
        $targetExecutor = new Executor($target);
        $statementCount = (new Restorer($targetExecutor))->restore($sql);

        self::assertGreaterThan(0, $statementCount);

        $users = iterator_to_array($targetExecutor->run('SELECT id, email, age FROM users ORDER BY id')->rows, false);
        self::assertSame(['id' => 1, 'email' => 'ann@example.com', 'age' => 30], $users[0]->toArray());
        self::assertSame(['id' => 2, 'email' => "o'brien@example.com", 'age' => 0], $users[1]->toArray());

        $orders = iterator_to_array($targetExecutor->run('SELECT id, user_id, total FROM orders')->rows, false);
        self::assertSame('99.50', $orders[0]->toArray()['total']);

        // The restored FOREIGN KEY ... ON DELETE CASCADE actually works,
        // not just parses - proving the constraint round-tripped as a
        // real, enforced constraint, not merely as matching DDL text.
        $targetExecutor->run('DELETE FROM users WHERE id = 1');
        self::assertSame([], iterator_to_array($targetExecutor->run('SELECT id FROM orders')->rows, false));

        // The restored CHECK still rejects what it always did.
        $this->expectException(ConstraintViolationException::class);

        try {
            $targetExecutor->run('INSERT INTO users (id, email, age) VALUES (3, null, -1)');
        } finally {
            $target->close();
        }
    }
}

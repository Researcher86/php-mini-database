<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Integration;

use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 20's "integration tests (embedded)": where `tests/Unit/Execution/*`
 * each isolate one feature (joins, group by, constraints, transactions...)
 * against a handful of rows, this exercises many of them together in one
 * continuous, realistic session against a single embedded `Database` — a
 * small blog schema, seeded, queried, and modified the way an application
 * actually would, rather than one feature at a time.
 */
final class SqlEndToEndTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $parameters = []): array
    {
        $result = $this->executor->run($sql, $parameters);
        self::assertInstanceOf(QueryResult::class, $result);

        return array_map(static fn (Row $row): array => $row->toArray(), iterator_to_array($result->rows, false));
    }

    /** @param list<mixed> $parameters */
    private function exec(string $sql, array $parameters = []): void
    {
        $this->executor->run($sql, $parameters);
    }

    private function schema(): void
    {
        $this->exec('CREATE TABLE authors (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        $this->exec(
            'CREATE TABLE posts ('
            . 'id INT PRIMARY KEY, '
            . 'author_id INT NOT NULL REFERENCES authors (id), '
            . 'title VARCHAR(100) NOT NULL, '
            . 'views INT NOT NULL DEFAULT 0'
            . ')',
        );
        $this->exec(
            'CREATE TABLE comments ('
            . 'id INT PRIMARY KEY, '
            . 'post_id INT NOT NULL REFERENCES posts (id) ON DELETE CASCADE, '
            . 'body VARCHAR(200) NOT NULL'
            . ')',
        );
        $this->exec('CREATE INDEX idx_posts_author ON posts (author_id)');
    }

    public function testASeededBlogSchemaSupportsAJoinedAggregateReport(): void
    {
        $this->schema();

        $this->exec("INSERT INTO authors (id, name) VALUES (1, 'Ann'), (2, 'Bob')");
        $this->exec(
            "INSERT INTO posts (id, author_id, title, views) VALUES "
            . "(1, 1, 'First post', 100), (2, 1, 'Second post', 50), (3, 2, 'Bob''s post', 10)",
        );
        $this->exec(
            'INSERT INTO comments (id, post_id, body) VALUES '
            . "(1, 1, 'Nice!'), (2, 1, 'Great read'), (3, 2, 'Meh')",
        );

        $report = $this->query(
            'SELECT a.name AS author, COUNT(p.id) AS post_count, SUM(p.views) AS total_views '
            . 'FROM authors a JOIN posts p ON p.author_id = a.id '
            . 'GROUP BY a.name '
            . 'HAVING SUM(p.views) > 20 '
            . 'ORDER BY total_views DESC',
        );

        self::assertSame(
            [
                ['author' => 'Ann', 'post_count' => 2, 'total_views' => 150],
            ],
            $report,
        );

        $commentCounts = $this->query(
            'SELECT p.title, COUNT(c.id) AS comments FROM posts p '
            . 'LEFT JOIN comments c ON c.post_id = p.id '
            . 'GROUP BY p.title ORDER BY title',
        );

        self::assertSame(
            [
                ['title' => "Bob's post", 'comments' => 0],
                ['title' => 'First post', 'comments' => 2],
                ['title' => 'Second post', 'comments' => 1],
            ],
            $commentCounts,
        );
    }

    public function testDeletingAParentCascadesAndKeepsTheRestOfTheDatabaseConsistent(): void
    {
        $this->schema();
        $this->exec("INSERT INTO authors (id, name) VALUES (1, 'Ann')");
        $this->exec("INSERT INTO posts (id, author_id, title) VALUES (1, 1, 'First post')");
        $this->exec("INSERT INTO comments (id, post_id, body) VALUES (1, 1, 'Nice!')");

        $this->exec('DELETE FROM posts WHERE id = 1');

        self::assertSame([], $this->query('SELECT * FROM comments'));
        self::assertSame([['id' => 1, 'name' => 'Ann']], $this->query('SELECT * FROM authors'));
    }

    public function testParameterisedQueriesAndUpdatesWorkAcrossTheSameSchema(): void
    {
        $this->schema();
        $this->exec("INSERT INTO authors (id, name) VALUES (1, 'Ann')");
        $this->exec(
            'INSERT INTO posts (id, author_id, title, views) VALUES (?, ?, ?, ?)',
            [1, 1, 'First post', 5],
        );

        $this->exec('UPDATE posts SET views = views + ? WHERE id = ?', [95, 1]);

        self::assertSame(
            [['title' => 'First post', 'views' => 100]],
            $this->query('SELECT title, views FROM posts WHERE author_id = ?', [1]),
        );
    }

    /**
     * The parser/planner do not support a correlated subquery yet (see
     * `ExpressionPrinterTest`'s docblock for the same boundary), so an
     * application filters in two round trips instead - itself worth
     * proving end to end, since it is the only way this schema can express
     * "authors with a popular post" today.
     */
    public function testAnInListBuiltFromAPriorQueryFiltersCorrectly(): void
    {
        $this->schema();
        $this->exec("INSERT INTO authors (id, name) VALUES (1, 'Ann'), (2, 'Bob')");
        $this->exec(
            "INSERT INTO posts (id, author_id, title, views) VALUES (1, 1, 'Popular', 500), (2, 2, 'Quiet', 1)",
        );

        $popularAuthorIds = array_column($this->query('SELECT author_id FROM posts WHERE views > 100'), 'author_id');
        self::assertSame([1], $popularAuthorIds);

        $popularAuthors = $this->query(
            sprintf('SELECT name FROM authors WHERE id IN (%s) ORDER BY name', implode(', ', $popularAuthorIds)),
        );

        self::assertSame([['name' => 'Ann']], $popularAuthors);
    }

    public function testASchemaChangeMidSessionKeepsTheDataAndTheIndexesItAddressedBy(): void
    {
        $this->schema();
        $this->exec("INSERT INTO authors (id, name) VALUES (1, 'Ann'), (2, 'Bob')");
        $this->exec(
            "INSERT INTO posts (id, author_id, title, views) VALUES "
            . "(1, 1, 'First post', 100), (2, 2, 'Bob''s post', 10)",
        );

        $this->exec("ALTER TABLE posts ADD COLUMN status VARCHAR(10) DEFAULT 'draft'");
        $this->exec("UPDATE posts SET status = 'published' WHERE views > 50");

        self::assertSame(
            [
                ['title' => "Bob's post", 'status' => 'draft'],
                ['title' => 'First post', 'status' => 'published'],
            ],
            $this->query('SELECT title, status FROM posts ORDER BY title'),
        );

        // idx_posts_author addressed records the rewrite has since moved.
        self::assertSame(
            [['title' => 'First post', 'status' => 'published']],
            $this->query('SELECT p.title, p.status FROM posts p WHERE p.author_id = 1'),
        );

        $this->exec('ALTER TABLE posts DROP COLUMN status');

        self::assertSame(
            [['id' => 1, 'author_id' => 1, 'title' => 'First post', 'views' => 100]],
            $this->query('SELECT * FROM posts WHERE author_id = 1'),
        );
    }
}

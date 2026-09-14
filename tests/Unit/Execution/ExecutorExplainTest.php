<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * `EXPLAIN` end to end: real SQL text in, the plan `Sql\Planner\Planner`
 * and `Sql\Optimizer\Optimizer` produced for it, printed one line per node.
 * Each unit — `Planner`, each `Sql\Optimizer\Rule\*` — has its own direct
 * test elsewhere; this file is where they are proven to describe a plan
 * together the way a caller reading `EXPLAIN`'s output actually would.
 */
final class ExecutorExplainTest extends TestCase
{
    use TemporaryDirectory;

    private Database $database;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->database = Database::open($this->path('mydb'));
        $this->executor = new Executor($this->database);
        $this->executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50), age INT)');
        $this->executor->run('CREATE INDEX idx_age ON users (age)');
        $this->executor->run('CREATE TABLE orders (id INT PRIMARY KEY, user_id INT, total INT)');
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param string $sql the SELECT to explain, without the leading EXPLAIN keyword
     *
     * @return list<string>
     */
    private function explain(string $sql): array
    {
        $result = $this->executor->run('EXPLAIN ' . $sql);
        self::assertInstanceOf(QueryResult::class, $result);
        self::assertSame(['plan'], $result->columns);

        return array_map(static fn (Row $row): string => $row->get('plan'), iterator_to_array($result->rows, false));
    }

    public function testASeqScanIsShownForAnUnindexedColumn(): void
    {
        $lines = $this->explain("SELECT id FROM users WHERE name = 'Ann'");

        self::assertStringContainsString('SeqScan users', $lines[array_key_last($lines)] ?? '');
    }

    public function testAnIndexScanIsShownForAnIndexedColumn(): void
    {
        $lines = $this->explain('SELECT id FROM users WHERE age = 30');

        $scanLine = $lines[array_key_last($lines)];
        self::assertStringContainsString('IndexScan users USING idx_age', $scanLine);
        self::assertStringContainsString('age = 30', $scanLine);
    }

    public function testTheTopLineIsTheOutermostNode(): void
    {
        $lines = $this->explain('SELECT id FROM users LIMIT 10');

        self::assertStringStartsWith('Limit 10', $lines[0]);
    }

    public function testAHashJoinIsShownForAPlainEquiJoin(): void
    {
        $lines = $this->explain('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id = u.id');

        self::assertTrue((bool) array_filter($lines, static fn (string $line): bool => str_contains($line, 'HashJoin')));
    }

    public function testANonEquiJoinShowsNestedLoopJoinInstead(): void
    {
        $lines = $this->explain('SELECT u.name, o.total FROM users u JOIN orders o ON o.user_id > u.id');

        self::assertTrue((bool) array_filter($lines, static fn (string $line): bool => str_contains($line, 'NestedLoopJoin')));
    }

    public function testChildrenAreIndentedUnderTheirParent(): void
    {
        // Limit -> Project -> Filter -> SeqScan: each nesting level adds
        // two more spaces than its parent.
        $lines = $this->explain("SELECT id FROM users WHERE name = 'Ann'");

        self::assertStringStartsWith('Limit', $lines[0]);
        self::assertStringStartsWith('  Project', $lines[1]);
        self::assertStringStartsWith('    Filter', $lines[2]);
        self::assertStringStartsWith('      SeqScan', $lines[3]);
    }

    public function testExplainRequiresAFromClause(): void
    {
        $this->expectException(ExecutionException::class);
        $this->executor->run('EXPLAIN SELECT 1 + 1');
    }

    public function testExplainDoesNotActuallyRunTheQuery(): void
    {
        $this->explain('SELECT id FROM users');

        // Running EXPLAIN must not have inserted, deleted, or otherwise
        // touched anything - a plain SELECT afterwards still sees nothing.
        $rows = $this->executor->run('SELECT id FROM users');
        self::assertInstanceOf(QueryResult::class, $rows);
        self::assertSame([], iterator_to_array($rows->rows, false));
    }
}

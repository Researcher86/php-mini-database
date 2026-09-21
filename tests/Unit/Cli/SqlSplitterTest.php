<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsMultipleStatements(): void
    {
        $statements = SqlSplitter::split('SELECT 1; SELECT 2;');

        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testATrailingStatementWithoutASemicolonIsIncluded(): void
    {
        $statements = SqlSplitter::split('SELECT 1; SELECT 2');

        self::assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testASemicolonInsideAStringLiteralDoesNotSplit(): void
    {
        $statements = SqlSplitter::split("INSERT INTO t (v) VALUES ('a;b'); SELECT 1;");

        self::assertSame(["INSERT INTO t (v) VALUES ('a;b')", 'SELECT 1'], $statements);
    }

    public function testACommentLeadingIntoTheNextStatementTravelsWithIt(): void
    {
        // The splitter finds statement *boundaries*; it does not strip
        // comments out of what it finds between them - the leading
        // comment here is harmless SQL text the server's own lexer skips
        // the same way it would on any other Query.
        $statements = SqlSplitter::split("SELECT 1;\n-- a comment\nSELECT 2;");

        self::assertSame('SELECT 1', $statements[0]);
        self::assertStringContainsString('-- a comment', $statements[1]);
        self::assertStringContainsString('SELECT 2', $statements[1]);
    }

    public function testATrailingCommentAfterTheLastStatementIsNotTreatedAsANewOne(): void
    {
        $statements = SqlSplitter::split("SELECT 1;\n-- trailing comment\n");

        self::assertSame(['SELECT 1'], $statements);
    }

    public function testEmptyAndWhitespaceOnlyInputProducesNoStatements(): void
    {
        self::assertSame([], SqlSplitter::split(''));
        self::assertSame([], SqlSplitter::split("   \n\t  "));
        self::assertSame([], SqlSplitter::split(';;;'));
    }

    public function testCommentOnlyInputProducesNoStatements(): void
    {
        self::assertSame([], SqlSplitter::split("-- just a comment\n"));
    }

    public function testMultilineStatementsAreKeptWhole(): void
    {
        $sql = "CREATE TABLE users (\n  id INT PRIMARY KEY,\n  name VARCHAR(50)\n);";

        $statements = SqlSplitter::split($sql);

        self::assertCount(1, $statements);
        self::assertStringContainsString('CREATE TABLE users', $statements[0]);
        self::assertStringContainsString('name VARCHAR(50)', $statements[0]);
    }
}

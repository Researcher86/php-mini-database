<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Exception\ParserException;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ParserErrorTest extends TestCase
{
    public function testAnIncompleteStatementThrows(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('SELECT * FROM');
    }

    public function testAMissingClosingParenthesisThrows(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('SELECT * FROM users WHERE (age > 1');
    }

    public function testAKeywordCannotStandInForAnIdentifier(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('SELECT * FROM select');
    }

    public function testAnUnknownStatementKeywordThrows(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('EXPLAIN SELECT 1');
    }

    public function testCreateWithoutTableOrIndexThrows(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('CREATE users (id INT)');
    }

    public function testParseOneRejectsMoreThanOneStatement(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('SELECT 1; SELECT 2;');
    }

    public function testParseOneRejectsAnEmptyStatement(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('');
    }

    public function testANotWithoutInLikeOrBetweenIsRejected(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('SELECT * FROM t WHERE a NOT NULL');
    }

    public function testTheExceptionReportsALineAndColumn(): void
    {
        try {
            Parser::parseOne("SELECT *\nFROM");
            self::fail('Expected a ParserException.');
        } catch (ParserException $e) {
            self::assertSame(2, $e->sourceLine);
            self::assertSame(5, $e->sourceColumn);
        }
    }

    public function testEmptySourceParsesToNoStatements(): void
    {
        self::assertSame([], Parser::parse(''));
    }

    public function testWhitespaceOnlySourceParsesToNoStatements(): void
    {
        self::assertSame([], Parser::parse("  \n  "));
    }

    public function testATrailingSemicolonIsOptional(): void
    {
        self::assertCount(1, Parser::parse('SELECT 1'));
        self::assertCount(1, Parser::parse('SELECT 1;'));
    }
}

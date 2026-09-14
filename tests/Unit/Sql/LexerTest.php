<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Exception\ParserException;
use PhpMiniDatabase\Sql\Lexer;
use PhpMiniDatabase\Sql\TokenType;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    /** @return list<TokenType> */
    private function types(string $sql): array
    {
        return array_map(static fn ($token) => $token->type, Lexer::tokenize($sql));
    }

    public function testEmptySourceIsJustEof(): void
    {
        self::assertSame([TokenType::EOF], $this->types(''));
    }

    public function testKeywordsAreRecognizedCaseInsensitively(): void
    {
        self::assertSame([TokenType::SELECT, TokenType::EOF], $this->types('select'));
        self::assertSame([TokenType::SELECT, TokenType::EOF], $this->types('SeLeCt'));
    }

    public function testBareIdentifier(): void
    {
        $tokens = Lexer::tokenize('users_2');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('users_2', $tokens[0]->text);
    }

    public function testQuotedIdentifierUnescapesDoubledQuotes(): void
    {
        $tokens = Lexer::tokenize('"a""b"');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('a"b', $tokens[0]->text);
    }

    public function testAQuotedIdentifierIsNeverMatchedAsAKeyword(): void
    {
        $tokens = Lexer::tokenize('"select"');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('select', $tokens[0]->text);
    }

    public function testStringLiteralUnescapesDoubledQuotes(): void
    {
        $tokens = Lexer::tokenize("'it''s'");

        self::assertSame(TokenType::STRING, $tokens[0]->type);
        self::assertSame("it's", $tokens[0]->text);
    }

    public function testIntegerAndDecimalNumbers(): void
    {
        $tokens = Lexer::tokenize('123 45.67');

        self::assertSame('123', $tokens[0]->text);
        self::assertSame(TokenType::NUMBER, $tokens[0]->type);
        self::assertSame('45.67', $tokens[1]->text);
        self::assertSame(TokenType::NUMBER, $tokens[1]->type);
    }

    /**
     * A dot not followed by a digit is punctuation (a qualified column
     * reference, `t.5` notwithstanding), not the start of a decimal.
     */
    public function testANumberDoesNotConsumeATrailingDotThatIsNotADecimalPoint(): void
    {
        self::assertSame([TokenType::NUMBER, TokenType::DOT, TokenType::EOF], $this->types('5.'));
    }

    public function testMultiCharacterOperators(): void
    {
        self::assertSame(
            [TokenType::LTE, TokenType::GTE, TokenType::NEQ, TokenType::NEQ, TokenType::EOF],
            $this->types('<= >= <> !='),
        );
    }

    public function testSingleCharacterPunctuation(): void
    {
        self::assertSame(
            [
                TokenType::LPAREN, TokenType::RPAREN, TokenType::COMMA, TokenType::SEMICOLON,
                TokenType::DOT, TokenType::STAR, TokenType::PLACEHOLDER, TokenType::EQ,
                TokenType::LT, TokenType::GT, TokenType::PLUS, TokenType::MINUS,
                TokenType::SLASH, TokenType::PERCENT, TokenType::EOF,
            ],
            // "<" and ">" separated so they are not read as the "<>" operator.
            $this->types('(),;.*?=< >+-/%'),
        );
    }

    public function testLineCommentsAreSkipped(): void
    {
        self::assertSame([TokenType::SELECT, TokenType::EOF], $this->types("-- a comment\nSELECT"));
    }

    public function testBlockCommentsAreSkipped(): void
    {
        self::assertSame([TokenType::SELECT, TokenType::EOF], $this->types('/* a\nmultiline\ncomment */ SELECT'));
    }

    public function testWhitespaceIsInsignificant(): void
    {
        self::assertSame([TokenType::SELECT, TokenType::FROM, TokenType::EOF], $this->types("SELECT\t\n  FROM"));
    }

    public function testTokenPositionsPointAtTheirStart(): void
    {
        $tokens = Lexer::tokenize('  SELECT');

        self::assertSame(2, $tokens[0]->position);
    }

    public function testAnUnterminatedStringThrows(): void
    {
        $this->expectException(ParserException::class);
        Lexer::tokenize("'unterminated");
    }

    public function testAnUnterminatedQuotedIdentifierThrows(): void
    {
        $this->expectException(ParserException::class);
        Lexer::tokenize('"unterminated');
    }

    public function testAnUnterminatedBlockCommentThrows(): void
    {
        $this->expectException(ParserException::class);
        Lexer::tokenize('/* never closed');
    }

    public function testAnUnexpectedCharacterThrows(): void
    {
        $this->expectException(ParserException::class);
        Lexer::tokenize('SELECT # FROM t');
    }

    public function testAnEmptyQuotedIdentifierIsRejected(): void
    {
        $this->expectException(ParserException::class);
        Lexer::tokenize('""');
    }

    public function testASimpleStatementTokenizesEndToEnd(): void
    {
        self::assertSame(
            [
                TokenType::SELECT, TokenType::STAR, TokenType::FROM, TokenType::IDENTIFIER,
                TokenType::WHERE, TokenType::IDENTIFIER, TokenType::GT, TokenType::NUMBER,
                TokenType::SEMICOLON, TokenType::EOF,
            ],
            $this->types('SELECT * FROM users WHERE age > 18;'),
        );
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql;

use PhpMiniDatabase\Exception\ParserException;

/**
 * Turns SQL text into a flat list of tokens, ending in one `EOF`.
 *
 * The whole list is produced up front rather than pulled lazily. A
 * statement is at most a few hundred tokens, small enough that the memory
 * cost is nothing, and `Parser` gets to look arbitrarily far ahead (or
 * backtrack) with plain array indexing instead of a peek buffer it has to
 * manage itself.
 *
 * A token's `$text` is already its *value* — quotes stripped, escapes
 * collapsed — never the raw span of source it came from. Both quoting
 * styles use `''` doubling to escape their own delimiter (`'it''s'`,
 * `"a""b"`), the one SQL-standard convention, not a backslash escape from
 * any particular database's dialect.
 */
final class Lexer
{
    private int $position = 0;
    private readonly int $length;

    private function __construct(
        private readonly string $source,
    ) {
        $this->length = strlen($source);
    }

    /**
     * @return list<Token>
     *
     * @throws ParserException on an unterminated string, quoted identifier,
     *                        comment, or a character that starts no token
     */
    public static function tokenize(string $source): array
    {
        return (new self($source))->run();
    }

    /** @return list<Token> */
    private function run(): array
    {
        $tokens = [];

        while (true) {
            $this->skipWhitespaceAndComments();

            if ($this->atEnd()) {
                $tokens[] = new Token(TokenType::EOF, '', $this->position);

                return $tokens;
            }

            $tokens[] = $this->next();
        }
    }

    private function next(): Token
    {
        $char = $this->source[$this->position];

        return match (true) {
            $char === '"' => $this->quotedIdentifier(),
            $char === "'" => $this->stringLiteral(),
            $this->isDigit($char) => $this->number(),
            $this->isIdentifierStart($char) => $this->identifierOrKeyword(),
            default => $this->operator(),
        };
    }

    private function quotedIdentifier(): Token
    {
        $start = $this->position;
        $text = $this->delimited('"', 'quoted identifier');

        if ($text === '') {
            throw ParserException::at($this->source, $start, 'Identifiers cannot be empty.');
        }

        return new Token(TokenType::IDENTIFIER, $text, $start);
    }

    private function stringLiteral(): Token
    {
        $start = $this->position;

        return new Token(TokenType::STRING, $this->delimited("'", 'string literal'), $start);
    }

    /**
     * Consume a $quote-delimited span with $quote doubled as its own escape,
     * and return the unescaped contents.
     */
    private function delimited(string $quote, string $kind): string
    {
        $start = $this->position;
        $this->position++; // opening quote

        $text = '';

        while (true) {
            if ($this->atEnd()) {
                throw ParserException::at($this->source, $start, sprintf('Unterminated %s.', $kind));
            }

            $char = $this->source[$this->position];

            if ($char !== $quote) {
                $text .= $char;
                $this->position++;
                continue;
            }

            // A doubled quote is an escaped literal quote; a lone one ends
            // the token.
            if ($this->peek(1) === $quote) {
                $text .= $quote;
                $this->position += 2;
                continue;
            }

            $this->position++;

            return $text;
        }
    }

    private function number(): Token
    {
        $start = $this->position;

        while (!$this->atEnd() && $this->isDigit($this->source[$this->position])) {
            $this->position++;
        }

        if (!$this->atEnd() && $this->source[$this->position] === '.' && $this->isDigit($this->peek(1) ?? '')) {
            $this->position++;

            while (!$this->atEnd() && $this->isDigit($this->source[$this->position])) {
                $this->position++;
            }
        }

        return new Token(TokenType::NUMBER, substr($this->source, $start, $this->position - $start), $start);
    }

    private function identifierOrKeyword(): Token
    {
        $start = $this->position;

        while (!$this->atEnd() && $this->isIdentifierPart($this->source[$this->position])) {
            $this->position++;
        }

        $text = substr($this->source, $start, $this->position - $start);
        $type = TokenType::keywords()[strtoupper($text)] ?? TokenType::IDENTIFIER;

        return new Token($type, $text, $start);
    }

    private function operator(): Token
    {
        $start = $this->position;

        $twoCharType = match (substr($this->source, $this->position, 2)) {
            '<=' => TokenType::LTE,
            '>=' => TokenType::GTE,
            '<>', '!=' => TokenType::NEQ,
            default => null,
        };

        if ($twoCharType !== null) {
            $this->position += 2;

            return new Token($twoCharType, substr($this->source, $start, 2), $start);
        }

        $type = match ($this->source[$this->position]) {
            '(' => TokenType::LPAREN,
            ')' => TokenType::RPAREN,
            ',' => TokenType::COMMA,
            ';' => TokenType::SEMICOLON,
            '.' => TokenType::DOT,
            '*' => TokenType::STAR,
            '?' => TokenType::PLACEHOLDER,
            '=' => TokenType::EQ,
            '<' => TokenType::LT,
            '>' => TokenType::GT,
            '+' => TokenType::PLUS,
            '-' => TokenType::MINUS,
            '/' => TokenType::SLASH,
            '%' => TokenType::PERCENT,
            default => throw ParserException::at(
                $this->source,
                $start,
                sprintf('Unexpected character "%s".', $this->source[$this->position]),
            ),
        };

        $this->position++;

        return new Token($type, substr($this->source, $start, 1), $start);
    }

    private function skipWhitespaceAndComments(): void
    {
        while (!$this->atEnd()) {
            $char = $this->source[$this->position];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $this->position++;
                continue;
            }

            if ($char === '-' && $this->peek(1) === '-') {
                $this->skipLineComment();
                continue;
            }

            if ($char === '/' && $this->peek(1) === '*') {
                $this->skipBlockComment();
                continue;
            }

            return;
        }
    }

    private function skipLineComment(): void
    {
        while (!$this->atEnd() && $this->source[$this->position] !== "\n") {
            $this->position++;
        }
    }

    private function skipBlockComment(): void
    {
        $start = $this->position;
        $this->position += 2;

        while (true) {
            if ($this->atEnd()) {
                throw ParserException::at($this->source, $start, 'Unterminated comment.');
            }

            if ($this->source[$this->position] === '*' && $this->peek(1) === '/') {
                $this->position += 2;

                return;
            }

            $this->position++;
        }
    }

    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private function isIdentifierStart(string $char): bool
    {
        return $char === '_' || ($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z');
    }

    private function isIdentifierPart(string $char): bool
    {
        return $this->isIdentifierStart($char) || $this->isDigit($char);
    }

    private function peek(int $ahead): ?string
    {
        $index = $this->position + $ahead;

        return $index < $this->length ? $this->source[$index] : null;
    }

    private function atEnd(): bool
    {
        return $this->position >= $this->length;
    }
}

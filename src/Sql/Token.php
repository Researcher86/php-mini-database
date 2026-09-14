<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql;

/**
 * One lexeme: its kind, its text, and where it started in the source.
 *
 * `$text` is already the *value*, not the raw spelling — a quoted
 * identifier's surrounding quotes are stripped and a string literal's `''`
 * escapes are collapsed by the lexer, so the parser never re-parses a
 * token's spelling. `$position` is a byte offset into the original SQL text,
 * carried so `ParserException` can report a line and column without the
 * lexer needing to track either as it goes.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $text,
        public int $position,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }
}

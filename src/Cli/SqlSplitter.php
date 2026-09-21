<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use PhpMiniDatabase\Sql\Lexer;
use PhpMiniDatabase\Sql\TokenType;

/**
 * A dump file's or a REPL line's raw SQL text, split into individual
 * statements on top-level `;` — needed because `Message\Query`, and
 * therefore `Client\Connection::query()`, only ever carries one statement
 * at a time (`Execution\Executor::run()` calls `Sql\Parser::parseOne()`,
 * singular): `Command\ImportCommand` and `Cli\Repl` both have to do this
 * splitting themselves before sending anything.
 *
 * A naive `explode(';', $sql)` would break on a `;` inside a string
 * literal (`INSERT INTO t VALUES ('a;b')`) or a comment. This reuses
 * `Sql\Lexer::tokenize()` instead — already quote- and comment-aware,
 * since the parser needs exactly that — and finds statement boundaries
 * from the *tokens*' own positions rather than re-deriving that logic.
 * A run of only comments/whitespace between two semicolons (or after the
 * last one) produces no token at all, so it is correctly dropped rather
 * than becoming an empty statement `Sql\Parser::parseOne()` would reject.
 */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $start = 0;
        $sawTokenSinceStart = false;

        foreach (Lexer::tokenize($sql) as $token) {
            if ($token->is(TokenType::EOF)) {
                break;
            }

            if ($token->is(TokenType::SEMICOLON)) {
                if ($sawTokenSinceStart) {
                    $statements[] = trim(substr($sql, $start, $token->position - $start));
                }

                $start = $token->position + 1;
                $sawTokenSinceStart = false;

                continue;
            }

            $sawTokenSinceStart = true;
        }

        if ($sawTokenSinceStart) {
            $statements[] = trim(substr($sql, $start));
        }

        return $statements;
    }
}

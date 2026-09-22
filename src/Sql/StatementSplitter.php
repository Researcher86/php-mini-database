<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql;

/**
 * A dump file's or a REPL line's raw SQL text, split into individual
 * statements on top-level `;` — needed because `Message\Query`, and
 * therefore `Client\Connection::query()`, only ever carries one statement
 * at a time (`Execution\Executor::run()` calls `Parser::parseOne()`,
 * singular): `Cli\Command\ImportCommand`, `Cli\Repl` and `Backup\Restorer`
 * all have to do this splitting themselves before running anything.
 *
 * A naive `explode(';', $sql)` would break on a `;` inside a string
 * literal (`INSERT INTO t VALUES ('a;b')`) or a comment. This reuses
 * `Lexer::tokenize()` instead — already quote- and comment-aware, since
 * the parser needs exactly that — and finds statement boundaries from the
 * *tokens*' own positions rather than re-deriving that logic. A run of
 * only comments/whitespace between two semicolons (or after the last one)
 * produces no token at all, so it is correctly dropped rather than
 * becoming an empty statement `Parser::parseOne()` would reject.
 *
 * Lives under `Sql\`, not `Cli\`, since Milestone 19's `Backup\Restorer`
 * needs it too and is not a CLI concern — moved here from `Cli\SqlSplitter`
 * once a second, non-CLI consumer existed. See DECISIONS.md.
 */
final class StatementSplitter
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

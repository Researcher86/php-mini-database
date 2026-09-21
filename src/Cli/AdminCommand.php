<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

/**
 * `SHOW STATUS`/`SHOW CONNECTIONS`/`KILL <id>`, recognized from plain text
 * inside `Repl` — PLAN.md §10.4 shows these as if they were SQL, but this
 * project's SQL grammar was never actually extended to parse them (see
 * DECISIONS.md): `Sql\Lexer`/`Sql\Parser` know nothing about `SHOW` or
 * `KILL`. This is client-side text recognition instead, translating
 * exactly these three fixed shapes into the typed wire messages
 * `Client\Connection::showStatus()`/`showConnections()`/`kill()` already
 * send — close enough to PLAN.md's illustration for the REPL to feel like
 * the example without teaching the parser new grammar for administration
 * commands alone. `Command\QueryCommand`'s one-shot CLI does not use
 * this: `bin/minidb status`/`connections`/`kill <id>` are real,
 * explicit subcommands there instead (see `Cli\ClientApplication`),
 * consistent with `user`/`import`/`export` already being subcommands
 * rather than SQL-shaped text.
 */
final class AdminCommand
{
    private function __construct(
        public readonly AdminCommandKind $kind,
        public readonly ?int $connectionId = null,
    ) {
    }

    public static function parse(string $text): ?self
    {
        $trimmed = trim($text);

        if (strcasecmp($trimmed, 'SHOW STATUS') === 0) {
            return new self(AdminCommandKind::SHOW_STATUS);
        }

        if (strcasecmp($trimmed, 'SHOW CONNECTIONS') === 0) {
            return new self(AdminCommandKind::SHOW_CONNECTIONS);
        }

        if (preg_match('/^KILL\s+(\d+)$/i', $trimmed, $matches) === 1) {
            return new self(AdminCommandKind::KILL, (int) $matches[1]);
        }

        return null;
    }
}

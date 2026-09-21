<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

/**
 * `$argv[1..]` (whatever comes after the script name), split into a
 * subcommand name, its positional arguments, and its `--name value`
 * options — generalized from the ad hoc parser `bin/minidb-user` used
 * before this phase folded it into `bin/minidb user` (see DECISIONS.md).
 *
 * A `--name` is a value-taking option (`$options[name][] = argv[i+1]`,
 * repeatable — `--table users --table posts` becomes `['users', 'posts']`)
 * unless it is named in `$booleanFlags`, in which case it takes no value
 * at all (`$flags[name] = true`) and the next token is left alone. This
 * split has to be told up front which names are boolean, rather than
 * guessed from context (`--json` followed by a positional SQL string
 * would otherwise swallow the SQL as `--json`'s "value") — every flag
 * this project defines is a fixed, known set, so there is nothing to
 * guess.
 */
final class ArgvParser
{
    /**
     * @param list<string> $argv
     * @param list<string> $booleanFlags
     *
     * @return array{
     *     command: ?string,
     *     args: list<string>,
     *     options: array<string, list<string>>,
     *     flags: array<string, bool>,
     * }
     */
    public static function parse(array $argv, array $booleanFlags = []): array
    {
        $command = null;
        $args = [];
        $options = [];
        $flags = [];
        $i = 0;
        $count = count($argv);

        while ($i < $count) {
            $token = $argv[$i];

            if (str_starts_with($token, '--')) {
                $name = substr($token, 2);

                if (in_array($name, $booleanFlags, true)) {
                    $flags[$name] = true;
                    $i++;

                    continue;
                }

                $options[$name][] = $argv[$i + 1] ?? '';
                $i += 2;

                continue;
            }

            if ($command === null) {
                $command = $token;
            } else {
                $args[] = $token;
            }

            $i++;
        }

        return ['command' => $command, 'args' => $args, 'options' => $options, 'flags' => $flags];
    }
}

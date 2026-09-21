<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli\Command;

use InvalidArgumentException;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Infrastructure\Path;
use PhpMiniDatabase\Network\Auth\UserStore;
use Throwable;

/**
 * `bin/minidb user add/remove/list` — PLAN.md §9.2's three subcommands,
 * under the single `bin/minidb` entrypoint that section shows, unlike
 * every other subcommand here this one never opens a connection: it
 * edits `users.json` directly through `Network\Auth\UserStore`, exactly
 * as the standalone `bin/minidb-user` script did before this phase folded
 * it in here (see DECISIONS.md for why it stays local rather than
 * becoming a network operation — there is no wire message for it).
 */
final class UserCommand
{
    /**
     * @param list<string>          $args
     * @param array<string, list<string>> $options
     * @param resource               $output
     * @param resource               $errorOutput
     */
    public function run(?string $subcommand, array $args, array $options, mixed $output, mixed $errorOutput): int
    {
        $dataDirectory = $options['data'][0] ?? null;

        if ($dataDirectory === null) {
            fwrite($errorOutput, "user requires --data <dir>.\n");

            return 1;
        }

        (new FileSystem())->ensureDirectory($dataDirectory);
        $users = new UserStore(Path::join($dataDirectory, 'users.json'));

        try {
            match ($subcommand) {
                'add' => $this->add($users, $args, $options, $output),
                'remove' => $this->remove($users, $args, $output),
                'list' => $this->list($users, $output),
                default => throw new InvalidArgumentException('Usage: user <add|remove|list> ...'),
            };

            return 0;
        } catch (Throwable $e) {
            fwrite($errorOutput, $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string>                $args
     * @param array<string, list<string>> $options
     * @param resource                    $output
     */
    private function add(UserStore $users, array $args, array $options, mixed $output): void
    {
        $username = $args[0] ?? throw new InvalidArgumentException('Usage: user add <username> --password <password> [--role <role>]...');
        $password = $options['password'][0] ?? throw new InvalidArgumentException('user add requires --password.');
        $users->create($username, $password, $options['role'] ?? []);
        fwrite($output, "Created user \"{$username}\".\n");
    }

    /**
     * @param list<string> $args
     * @param resource     $output
     */
    private function remove(UserStore $users, array $args, mixed $output): void
    {
        $username = $args[0] ?? throw new InvalidArgumentException('Usage: user remove <username>');
        $users->remove($username);
        fwrite($output, "Removed user \"{$username}\".\n");
    }

    /** @param resource $output */
    private function list(UserStore $users, mixed $output): void
    {
        foreach ($users->usernames() as $username) {
            fwrite($output, $username . "\n");
        }
    }
}

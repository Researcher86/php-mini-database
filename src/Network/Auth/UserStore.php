<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Auth;

use PhpMiniDatabase\Exception\ServerException;
use PhpMiniDatabase\Infrastructure\AtomicWriter;
use PhpMiniDatabase\Infrastructure\FileSystem;

/**
 * Every user this server knows about, kept in one `users.json` — PLAN.md
 * §6.4's file, minus the `salt` field that example shows: this project
 * derives a user's salt from their username (`PasswordHash::saltFor()`)
 * rather than storing one, so there is nothing left to keep in sync with
 * it (see DECISIONS.md). `AtomicWriter` is what `catalog.json`/`schema.json`
 * already use for the same reason a credentials file needs it even more:
 * a half-written `users.json` after a crash would be a real outage, not
 * merely a stale read.
 */
final class UserStore
{
    public function __construct(
        private readonly string $path,
        private readonly FileSystem $files = new FileSystem(),
        private readonly AtomicWriter $writer = new AtomicWriter(),
    ) {
    }

    public function find(string $username): ?UserRecord
    {
        return $this->all()[$username] ?? null;
    }

    /** @param list<string> $roles */
    public function create(string $username, string $password, array $roles = []): void
    {
        $users = $this->all();

        if (isset($users[$username])) {
            throw new ServerException(sprintf('User "%s" already exists.', $username));
        }

        $hash = PasswordHash::derive($password, PasswordHash::saltFor($username));
        $users[$username] = new UserRecord($username, $hash, $roles);
        $this->save($users);
    }

    public function remove(string $username): void
    {
        $users = $this->all();

        if (!isset($users[$username])) {
            throw new ServerException(sprintf('User "%s" does not exist.', $username));
        }

        unset($users[$username]);
        $this->save($users);
    }

    /** @return list<string> */
    public function usernames(): array
    {
        $usernames = array_keys($this->all());
        sort($usernames);

        return $usernames;
    }

    /** @return array<string, UserRecord> */
    private function all(): array
    {
        if (!$this->files->exists($this->path)) {
            return [];
        }

        /** @var array<string, array{hash: string, roles: list<string>}> $data */
        $data = json_decode($this->files->read($this->path), true, flags: JSON_THROW_ON_ERROR);
        $users = [];

        foreach ($data as $username => $record) {
            $hash = base64_decode($record['hash'], true);

            if ($hash === false) {
                throw new ServerException(sprintf('User "%s" has a corrupted password hash in "%s".', $username, $this->path));
            }

            $users[$username] = new UserRecord($username, $hash, $record['roles']);
        }

        return $users;
    }

    /** @param array<string, UserRecord> $users */
    private function save(array $users): void
    {
        $data = [];

        foreach ($users as $username => $user) {
            $data[$username] = ['hash' => base64_encode($user->hash), 'roles' => $user->roles];
        }

        $this->writer->write($this->path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 0600);
    }
}

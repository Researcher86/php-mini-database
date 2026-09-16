<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Auth;

/**
 * Login/password verification (PLAN.md §3.3) — the one thing `Network\Session`
 * asks of authentication: a fresh `nonce()` to hand the client at `HELLO`,
 * and `verify()` to check what comes back in `AUTH` against `UserStore`,
 * with `LoginThrottle` consulted either side of it.
 *
 * `verify()` gives the same generic reason for an unknown username as for
 * a wrong password — confirming which one it was would tell an attacker
 * whether a username exists at all, for free.
 */
final class Authenticator
{
    public function __construct(
        private readonly UserStore $users,
        private readonly LoginThrottle $throttle = new LoginThrottle(),
    ) {
    }

    public function nonce(): string
    {
        return ScramChallenge::nonce();
    }

    /** @return string|null a failure reason, or null once $response is accepted */
    public function verify(string $username, string $nonce, string $response): ?string
    {
        if ($this->throttle->isLocked($username)) {
            return 'Too many failed attempts; try again later.';
        }

        $user = $this->users->find($username);

        if ($user === null || !ScramChallenge::verify($user->hash, $nonce, $response)) {
            $this->throttle->recordFailure($username);

            return 'Invalid username or password.';
        }

        $this->throttle->recordSuccess($username);

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Auth;

/**
 * The challenge-response half of PLAN.md §5.5, once `PasswordHash` has
 * already turned a password into the shared secret both sides compare
 * against: a fresh `nonce()` per connection, and `respond()`/`verify()`
 * as the two ends of the same `HMAC-SHA256(hash, nonce)` computation —
 * one, a real client (or this project's own tests, standing in for one
 * before Milestone 16 exists) calls to prove it knows the password
 * without sending it; the other, `Authenticator` calls to check the
 * proof, using `hash_equals()` so a wrong guess is rejected in constant
 * time rather than one comparison stopping early at the first differing
 * byte.
 */
final class ScramChallenge
{
    public const NONCE_LENGTH = 16;

    public static function nonce(): string
    {
        return random_bytes(self::NONCE_LENGTH);
    }

    public static function respond(string $hash, string $nonce): string
    {
        return hash_hmac('sha256', $nonce, $hash, true);
    }

    public static function verify(string $hash, string $nonce, string $response): bool
    {
        return hash_equals(self::respond($hash, $nonce), $response);
    }
}

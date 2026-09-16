<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Auth;

/**
 * A password, turned into the raw bytes `UserStore` keeps and
 * `ScramChallenge` uses as an HMAC key — Argon2id (`sodium_crypto_pwhash()`),
 * per PLAN.md §13.1, in its *raw-output* form rather than the more usual
 * `password_hash()`/`password_verify()` pair.
 *
 * That choice is forced by what `ScramChallenge` needs, not a preference:
 * the challenge-response scheme (PLAN.md §5.5) proves a client knows the
 * password *without sending it*, by having both sides independently
 * compute `HMAC(hash, nonce)` and comparing — which requires the raw hash
 * bytes as a key. `password_verify()` only ever answers "yes" or "no"; it
 * never hands back the bytes an HMAC could be built from. `derive()` is
 * exactly the piece both a real client (Milestone 16) and the server
 * would run, and both need to arrive at the identical byte string given
 * the same password and salt.
 *
 * `saltFor()` derives the salt from the username itself
 * (`SHA-256(username)`, truncated to the length `sodium_crypto_pwhash()`
 * requires) rather than a random value stored alongside the hash — see
 * DECISIONS.md for why, and what it costs.
 */
final class PasswordHash
{
    public const HASH_LENGTH = 32;

    public static function derive(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            self::HASH_LENGTH,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    public static function saltFor(string $username): string
    {
        return substr(hash('sha256', $username, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Auth;

use PhpMiniDatabase\Network\Auth\PasswordHash;
use PHPUnit\Framework\TestCase;

final class PasswordHashTest extends TestCase
{
    public function testDerivingTheSamePasswordAndSaltTwiceGivesTheSameHash(): void
    {
        $salt = PasswordHash::saltFor('alice');

        self::assertSame(
            PasswordHash::derive('secret', $salt),
            PasswordHash::derive('secret', $salt),
        );
    }

    public function testDifferentPasswordsGiveDifferentHashes(): void
    {
        $salt = PasswordHash::saltFor('alice');

        self::assertNotSame(
            PasswordHash::derive('secret', $salt),
            PasswordHash::derive('another', $salt),
        );
    }

    public function testDifferentUsernamesGiveDifferentSalts(): void
    {
        self::assertNotSame(PasswordHash::saltFor('alice'), PasswordHash::saltFor('bob'));
    }

    public function testTheSameUsernameAlwaysGivesTheSameSalt(): void
    {
        self::assertSame(PasswordHash::saltFor('alice'), PasswordHash::saltFor('alice'));
    }

    public function testTheSamePasswordUnderDifferentUsernamesHashesDifferently(): void
    {
        self::assertNotSame(
            PasswordHash::derive('secret', PasswordHash::saltFor('alice')),
            PasswordHash::derive('secret', PasswordHash::saltFor('bob')),
        );
    }

    public function testTheSaltHasTheLengthSodiumRequires(): void
    {
        self::assertSame(SODIUM_CRYPTO_PWHASH_SALTBYTES, strlen(PasswordHash::saltFor('alice')));
    }

    public function testTheDerivedHashHasTheDocumentedLength(): void
    {
        self::assertSame(PasswordHash::HASH_LENGTH, strlen(PasswordHash::derive('secret', PasswordHash::saltFor('alice'))));
    }
}

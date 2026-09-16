<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Auth;

use PhpMiniDatabase\Network\Auth\PasswordHash;
use PhpMiniDatabase\Network\Auth\ScramChallenge;
use PHPUnit\Framework\TestCase;

final class ScramChallengeTest extends TestCase
{
    public function testTwoNoncesAreNotTheSame(): void
    {
        self::assertNotSame(ScramChallenge::nonce(), ScramChallenge::nonce());
    }

    public function testANonceHasTheDocumentedLength(): void
    {
        self::assertSame(ScramChallenge::NONCE_LENGTH, strlen(ScramChallenge::nonce()));
    }

    public function testTheCorrectResponseVerifies(): void
    {
        $hash = PasswordHash::derive('secret', PasswordHash::saltFor('alice'));
        $nonce = ScramChallenge::nonce();

        $response = ScramChallenge::respond($hash, $nonce);

        self::assertTrue(ScramChallenge::verify($hash, $nonce, $response));
    }

    public function testAResponseComputedWithTheWrongHashFailsToVerify(): void
    {
        $hash = PasswordHash::derive('secret', PasswordHash::saltFor('alice'));
        $wrongHash = PasswordHash::derive('wrong-password', PasswordHash::saltFor('alice'));
        $nonce = ScramChallenge::nonce();

        $response = ScramChallenge::respond($wrongHash, $nonce);

        self::assertFalse(ScramChallenge::verify($hash, $nonce, $response));
    }

    public function testAResponseForADifferentNonceFailsToVerify(): void
    {
        $hash = PasswordHash::derive('secret', PasswordHash::saltFor('alice'));
        $response = ScramChallenge::respond($hash, ScramChallenge::nonce());

        self::assertFalse(ScramChallenge::verify($hash, ScramChallenge::nonce(), $response));
    }

    public function testAnArbitraryResponseFailsToVerify(): void
    {
        $hash = PasswordHash::derive('secret', PasswordHash::saltFor('alice'));

        self::assertFalse(ScramChallenge::verify($hash, ScramChallenge::nonce(), 'not-a-real-response'));
    }
}

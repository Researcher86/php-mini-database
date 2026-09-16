<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Auth;

use PhpMiniDatabase\Network\Auth\Authenticator;
use PhpMiniDatabase\Network\Auth\LoginThrottle;
use PhpMiniDatabase\Network\Auth\PasswordHash;
use PhpMiniDatabase\Network\Auth\ScramChallenge;
use PhpMiniDatabase\Network\Auth\UserStore;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    use TemporaryDirectory;

    private UserStore $users;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->users = new UserStore($this->path('users.json'));
        $this->users->create('alice', 'secret');
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function respondAs(string $username, string $password, string $nonce): string
    {
        return ScramChallenge::respond(PasswordHash::derive($password, PasswordHash::saltFor($username)), $nonce);
    }

    public function testTheCorrectPasswordVerifiesSuccessfully(): void
    {
        $authenticator = new Authenticator($this->users);
        $nonce = $authenticator->nonce();

        $reason = $authenticator->verify('alice', $nonce, $this->respondAs('alice', 'secret', $nonce));

        self::assertNull($reason);
    }

    public function testTheWrongPasswordFails(): void
    {
        $authenticator = new Authenticator($this->users);
        $nonce = $authenticator->nonce();

        $reason = $authenticator->verify('alice', $nonce, $this->respondAs('alice', 'wrong-password', $nonce));

        self::assertNotNull($reason);
    }

    public function testAnUnknownUsernameFailsWithTheSameReasonAsAWrongPassword(): void
    {
        $authenticator = new Authenticator($this->users);
        $nonce = $authenticator->nonce();

        $unknownReason = $authenticator->verify('nobody', $nonce, $this->respondAs('nobody', 'secret', $nonce));
        $wrongPasswordReason = $authenticator->verify('alice', $nonce, $this->respondAs('alice', 'wrong', $nonce));

        // Neither failure tells an attacker which part was wrong.
        self::assertSame($wrongPasswordReason, $unknownReason);
    }

    public function testAFailureThenARetryWithTheRightPasswordSucceeds(): void
    {
        $authenticator = new Authenticator($this->users);
        $nonce = $authenticator->nonce();

        self::assertNotNull($authenticator->verify('alice', $nonce, $this->respondAs('alice', 'wrong', $nonce)));
        self::assertNull($authenticator->verify('alice', $nonce, $this->respondAs('alice', 'secret', $nonce)));
    }

    public function testEnoughFailuresLockTheAccountEvenWithTheRightPasswordAfterward(): void
    {
        $authenticator = new Authenticator($this->users, new LoginThrottle(maxAttempts: 2));
        $nonce = $authenticator->nonce();

        for ($i = 0; $i < 3; $i++) {
            $authenticator->verify('alice', $nonce, $this->respondAs('alice', 'wrong', $nonce));
        }

        $reason = $authenticator->verify('alice', $nonce, $this->respondAs('alice', 'secret', $nonce));

        self::assertNotNull($reason);
    }
}

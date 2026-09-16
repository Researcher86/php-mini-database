<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Auth;

use PhpMiniDatabase\Exception\ServerException;
use PhpMiniDatabase\Network\Auth\PasswordHash;
use PhpMiniDatabase\Network\Auth\ScramChallenge;
use PhpMiniDatabase\Network\Auth\UserStore;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class UserStoreTest extends TestCase
{
    use TemporaryDirectory;

    private UserStore $users;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->users = new UserStore($this->path('users.json'));
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testFindOnAnEmptyStoreReturnsNull(): void
    {
        self::assertNull($this->users->find('alice'));
    }

    public function testACreatedUserCanBeFound(): void
    {
        $this->users->create('alice', 'secret', ['admin']);

        $user = $this->users->find('alice');

        self::assertNotNull($user);
        self::assertSame('alice', $user->username);
        self::assertSame(['admin'], $user->roles);
    }

    public function testTheStoredHashIsWhatAuthenticationWouldExpect(): void
    {
        $this->users->create('alice', 'secret');

        $user = $this->users->find('alice');
        self::assertNotNull($user);

        $expectedHash = PasswordHash::derive('secret', PasswordHash::saltFor('alice'));
        self::assertSame($expectedHash, $user->hash);
    }

    public function testCreatingTheSameUsernameTwiceThrows(): void
    {
        $this->users->create('alice', 'secret');

        $this->expectException(ServerException::class);
        $this->users->create('alice', 'another');
    }

    public function testRemovingAnUnknownUserThrows(): void
    {
        $this->expectException(ServerException::class);
        $this->users->remove('nobody');
    }

    public function testARemovedUserCanNoLongerBeFound(): void
    {
        $this->users->create('alice', 'secret');
        $this->users->remove('alice');

        self::assertNull($this->users->find('alice'));
    }

    public function testUsernamesListsEveryoneInSortedOrder(): void
    {
        $this->users->create('bob', 'secret');
        $this->users->create('alice', 'secret');

        self::assertSame(['alice', 'bob'], $this->users->usernames());
    }

    public function testUsersPersistAcrossASeparateStoreInstance(): void
    {
        $this->users->create('alice', 'secret', ['reader']);

        $reopened = new UserStore($this->path('users.json'));
        $user = $reopened->find('alice');

        self::assertNotNull($user);
        self::assertSame(['reader'], $user->roles);
    }

    public function testAFreshlyCreatedUserAuthenticatesWithTheRightPassword(): void
    {
        $this->users->create('alice', 'secret');
        $user = $this->users->find('alice');
        self::assertNotNull($user);

        $nonce = ScramChallenge::nonce();
        $clientHash = PasswordHash::derive('secret', PasswordHash::saltFor('alice'));
        $response = ScramChallenge::respond($clientHash, $nonce);

        self::assertTrue(ScramChallenge::verify($user->hash, $nonce, $response));
    }
}

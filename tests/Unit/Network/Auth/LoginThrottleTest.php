<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Auth;

use PhpMiniDatabase\Network\Auth\LoginThrottle;
use PhpMiniDatabase\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    public function testAFreshUsernameIsNeverLocked(): void
    {
        $throttle = new LoginThrottle();

        self::assertFalse($throttle->isLocked('alice'));
    }

    public function testFailuresUpToMaxAttemptsDoNotLockTheAccount(): void
    {
        $throttle = new LoginThrottle(maxAttempts: 3);

        $throttle->recordFailure('alice');
        $throttle->recordFailure('alice');
        $throttle->recordFailure('alice');

        self::assertFalse($throttle->isLocked('alice'));
    }

    public function testOneFailureBeyondMaxAttemptsLocksTheAccount(): void
    {
        $throttle = new LoginThrottle(maxAttempts: 3);

        for ($i = 0; $i < 4; $i++) {
            $throttle->recordFailure('alice');
        }

        self::assertTrue($throttle->isLocked('alice'));
    }

    public function testTheLockExpiresOnceTheDelayHasPassed(): void
    {
        $clock = new FakeClock();
        $throttle = new LoginThrottle(maxAttempts: 1, baseDelaySeconds: 1.0, clock: $clock);

        $throttle->recordFailure('alice');
        $throttle->recordFailure('alice');
        self::assertTrue($throttle->isLocked('alice'));

        $clock->advanceBy(1.5);
        self::assertFalse($throttle->isLocked('alice'));
    }

    public function testTheDelayBacksOffExponentially(): void
    {
        $clock = new FakeClock();
        $throttle = new LoginThrottle(maxAttempts: 1, baseDelaySeconds: 1.0, clock: $clock);

        $throttle->recordFailure('alice'); // 1st: free
        $throttle->recordFailure('alice'); // 2nd: locks for 1s (2^0)
        $clock->advanceBy(1.5);
        self::assertFalse($throttle->isLocked('alice'));

        $throttle->recordFailure('alice'); // 3rd: locks for 2s (2^1)
        $clock->advanceBy(1.5);
        self::assertTrue($throttle->isLocked('alice'), 'a 1.5s wait should not clear a 2s lockout');

        $clock->advanceBy(1.0);
        self::assertFalse($throttle->isLocked('alice'));
    }

    public function testTheDelayIsCappedAtTheMaximum(): void
    {
        $clock = new FakeClock();
        $throttle = new LoginThrottle(maxAttempts: 1, baseDelaySeconds: 1.0, maxDelaySeconds: 5.0, clock: $clock);

        for ($i = 0; $i < 10; $i++) {
            $throttle->recordFailure('alice');
            $clock->advanceBy(0.0);
        }

        // Whatever the uncapped formula would have reached (2^8 seconds),
        // the lock must be gone 5s later, never more.
        $clock->advanceBy(5.5);
        self::assertFalse($throttle->isLocked('alice'));
    }

    public function testASuccessResetsTheFailureCount(): void
    {
        $throttle = new LoginThrottle(maxAttempts: 3);

        $throttle->recordFailure('alice');
        $throttle->recordFailure('alice');
        $throttle->recordSuccess('alice');

        for ($i = 0; $i < 4; $i++) {
            $throttle->recordFailure('alice');
        }

        // If the earlier failures had not been reset, this would already
        // be well past maxAttempts and locked from the very first of
        // these four.
        self::assertTrue($throttle->isLocked('alice'));
    }

    public function testUsernamesAreThrottledIndependently(): void
    {
        $throttle = new LoginThrottle(maxAttempts: 1);

        for ($i = 0; $i < 3; $i++) {
            $throttle->recordFailure('alice');
        }

        self::assertTrue($throttle->isLocked('alice'));
        self::assertFalse($throttle->isLocked('bob'));
    }
}

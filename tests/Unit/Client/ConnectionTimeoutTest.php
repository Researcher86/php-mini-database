<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Client;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Client\Connection;
use PHPUnit\Framework\TestCase;

/**
 * `$readTimeoutSeconds` actually firing - a bare listening socket that
 * accepts a connection and then says nothing, standing in for a hung or
 * overloaded server. This needs no real `Network\Server` at all (unlike
 * `ConnectionTest`): nothing server-side has to *react*, only stay
 * silent, so one test process driving both ends directly is enough here.
 *
 * `$writeTimeoutSeconds` is not exercised the same way: reliably forcing
 * a loopback socket's write to actually block needs its kernel send
 * buffer full, which - unlike a socket that simply never replies - is not
 * something this suite tries to simulate deterministically. See
 * DECISIONS.md.
 */
final class ConnectionTimeoutTest extends TestCase
{
    /** @var resource|null */
    private mixed $listener = null;

    protected function tearDown(): void
    {
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
    }

    public function testAReadTimeoutFiresWhenTheServerNeverReplies(): void
    {
        $this->listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($this->listener, $errorMessage);

        $address = stream_socket_get_name($this->listener, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $config = new ClientConfig(port: $port, connectTimeoutSeconds: 1.0, readTimeoutSeconds: 0.2);

        $start = microtime(true);

        try {
            Connection::connect($config);
            self::fail('Expected a ClientException.');
        } catch (ClientException $e) {
            self::assertStringContainsString('Timed out', $e->getMessage());
        }

        // Generous upper bound - proves the timeout fired on its own
        // schedule rather than the call hanging indefinitely, without
        // pinning this test to exact timing.
        self::assertLessThan(5.0, microtime(true) - $start);
    }
}

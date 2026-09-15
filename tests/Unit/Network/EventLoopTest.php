<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\EventLoop;
use PHPUnit\Framework\TestCase;

final class EventLoopTest extends TestCase
{
    private EventLoop $loop;

    /** @var list<resource> */
    private array $pair;

    protected function setUp(): void
    {
        $this->loop = new EventLoop();

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->pair = $pair;
    }

    protected function tearDown(): void
    {
        foreach ($this->pair as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testTickWithNoReadersReturnsImmediately(): void
    {
        $start = microtime(true);
        $this->loop->tick(1.0);

        self::assertLessThan(0.5, microtime(true) - $start);
    }

    public function testACallbackFiresWhenItsStreamBecomesReadable(): void
    {
        $received = null;
        $this->loop->onReadable($this->pair[0], function ($stream) use (&$received): void {
            $received = fread($stream, 1024);
        });

        fwrite($this->pair[1], 'hello');
        $this->loop->tick(1.0);

        self::assertSame('hello', $received);
    }

    public function testTickReturnsPromptlyWhenNothingIsReady(): void
    {
        $this->loop->onReadable($this->pair[0], static function (): void {
        });

        $start = microtime(true);
        $this->loop->tick(0.2);

        self::assertGreaterThanOrEqual(0.2, microtime(true) - $start);
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testRemoveReadableStopsTheCallbackFromFiring(): void
    {
        $fired = false;
        $this->loop->onReadable($this->pair[0], function () use (&$fired): void {
            $fired = true;
        });
        $this->loop->removeReadable($this->pair[0]);

        fwrite($this->pair[1], 'hello');
        $this->loop->tick(0.2);

        self::assertFalse($fired);
    }

    public function testACallbackCanRemoveAnotherStreamInTheSameBatchSafely(): void
    {
        $pair2 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair2);
        stream_set_blocking($pair2[0], false);
        stream_set_blocking($pair2[1], false);

        $secondFired = false;
        $this->loop->onReadable($this->pair[0], function () use ($pair2): void {
            $this->loop->removeReadable($pair2[0]);
        });
        $this->loop->onReadable($pair2[0], function () use (&$secondFired): void {
            $secondFired = true;
        });

        fwrite($this->pair[1], 'x');
        fwrite($pair2[1], 'x');
        $this->loop->tick(1.0);

        self::assertFalse($secondFired);

        fclose($pair2[0]);
        fclose($pair2[1]);
    }

    public function testRunStopsOnceStopIsCalled(): void
    {
        $ticks = 0;
        $this->loop->onReadable($this->pair[0], function () use (&$ticks): void {
            $ticks++;

            if ($ticks >= 3) {
                $this->loop->stop();
            } else {
                fwrite($this->pair[1], 'x');
            }
        });

        fwrite($this->pair[1], 'x');
        $this->loop->run(0.2);

        self::assertSame(3, $ticks);
    }
}

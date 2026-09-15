<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Protocol;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PHPUnit\Framework\TestCase;

final class FrameReaderTest extends TestCase
{
    private FrameReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FrameReader();
    }

    public function testNextReturnsNullOnAnEmptyBuffer(): void
    {
        self::assertNull($this->reader->next());
    }

    public function testAWholeFrameFedInOneCallIsReadImmediately(): void
    {
        $frame = new Frame(MessageType::PING);
        $this->reader->feed($frame->toBytes());

        $read = $this->reader->next();

        self::assertNotNull($read);
        self::assertSame(MessageType::PING, $read->type);
    }

    public function testAFrameSplitAcrossManyFeedsIsOnlyReadOnceComplete(): void
    {
        $bytes = (new Frame(MessageType::QUERY, 'SELECT 1'))->toBytes();

        foreach (str_split($bytes, 3) as $chunk) {
            self::assertNull($this->reader->next());
            $this->reader->feed($chunk);
        }

        $read = $this->reader->next();
        self::assertNotNull($read);
        self::assertSame('SELECT 1', $read->payload);
    }

    public function testMultipleFramesInOneFeedAreReadOneAtATime(): void
    {
        $this->reader->feed((new Frame(MessageType::PING))->toBytes() . (new Frame(MessageType::PONG))->toBytes());

        $first = $this->reader->next();
        $second = $this->reader->next();

        self::assertSame(MessageType::PING, $first?->type);
        self::assertSame(MessageType::PONG, $second?->type);
        self::assertNull($this->reader->next());
    }

    public function testBytesAfterAFullFrameStayBufferedUntilTheyCompleteAnotherOne(): void
    {
        $whole = (new Frame(MessageType::PING))->toBytes();
        $partialNext = substr((new Frame(MessageType::PONG))->toBytes(), 0, 5);

        $this->reader->feed($whole . $partialNext);

        self::assertSame(MessageType::PING, $this->reader->next()?->type);
        self::assertNull($this->reader->next());

        $this->reader->feed(substr((new Frame(MessageType::PONG))->toBytes(), 5));
        self::assertSame(MessageType::PONG, $this->reader->next()?->type);
    }

    public function testABadMagicPartwayThroughTheStreamThrows(): void
    {
        $this->reader->feed('XXXX' . str_repeat("\x00", Frame::HEADER_SIZE - 4));

        $this->expectException(ProtocolException::class);
        $this->reader->next();
    }
}

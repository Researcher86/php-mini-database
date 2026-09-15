<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Protocol;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    public function testARoundTripPreservesEveryField(): void
    {
        $frame = new Frame(MessageType::QUERY, 'SELECT 1', flags: 7, version: 3);

        $decoded = Frame::fromBytes($frame->toBytes());

        self::assertSame(MessageType::QUERY, $decoded->type);
        self::assertSame('SELECT 1', $decoded->payload);
        self::assertSame(7, $decoded->flags);
        self::assertSame(3, $decoded->version);
    }

    public function testAnEmptyPayloadRoundTrips(): void
    {
        $frame = new Frame(MessageType::PING);

        self::assertSame('', Frame::fromBytes($frame->toBytes())->payload);
    }

    public function testToBytesStartsWithTheMagic(): void
    {
        $frame = new Frame(MessageType::PING);

        self::assertStringStartsWith(Frame::MAGIC, $frame->toBytes());
    }

    public function testABadMagicIsRejected(): void
    {
        $bytes = 'XXXX' . str_repeat("\x00", Frame::HEADER_SIZE - 4);

        $this->expectException(ProtocolException::class);
        Frame::fromBytes($bytes);
    }

    public function testATruncatedHeaderIsRejected(): void
    {
        $this->expectException(ProtocolException::class);
        Frame::fromBytes(Frame::MAGIC . "\x00\x01");
    }

    public function testAPayloadShorterThanDeclaredIsRejected(): void
    {
        $frame = new Frame(MessageType::QUERY, 'hello');
        $bytes = $frame->toBytes();

        $this->expectException(ProtocolException::class);
        Frame::fromBytes(substr($bytes, 0, -2));
    }

    public function testTrailingBytesAfterTheDeclaredPayloadAreRejected(): void
    {
        $frame = new Frame(MessageType::QUERY, 'hello');

        $this->expectException(ProtocolException::class);
        Frame::fromBytes($frame->toBytes() . 'extra');
    }

    public function testAnUnknownMessageTypeIsRejected(): void
    {
        $header = Frame::MAGIC . pack('n', Frame::CURRENT_VERSION) . pack('C', 0xFE) . pack('C', 0) . pack('N', 0);

        $this->expectException(ProtocolException::class);
        Frame::fromBytes($header);
    }

    public function testAnOversizedPayloadIsRejectedAtConstruction(): void
    {
        $this->expectException(ProtocolException::class);
        new Frame(MessageType::QUERY, str_repeat('x', Frame::MAX_PAYLOAD_SIZE + 1));
    }
}

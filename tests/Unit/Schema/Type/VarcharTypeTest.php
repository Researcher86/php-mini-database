<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\Type;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PHPUnit\Framework\TestCase;

final class VarcharTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new VarcharType(255);
    }

    public function testNameIncludesLength(): void
    {
        self::assertSame('VARCHAR(255)', $this->type->name());
    }

    public function testCastNormalizesScalarsToStrings(): void
    {
        self::assertSame('42', $this->type->cast(42));
        self::assertSame('hello', $this->type->cast('hello'));
        self::assertSame('1', $this->type->cast(true));
    }

    public function testCastRejectsStringsAndBytesBeyondLimit(): void
    {
        $this->expectException(TypeException::class);
        $this->type->cast(str_repeat('a', 256));
    }

    public function testCanCastExactlyLengthInBytes(): void
    {
        self::assertSame(str_repeat('a', 255), $this->type->cast(str_repeat('a', 255)));
    }

    public function testLengthCountsBytesNotCharacters(): void
    {
        $utf8Type = new VarcharType(3);

        $this->expectException(TypeException::class);
        $utf8Type->cast('äöü'); // four bytes: C3 A4 C3 B6 C3 BC = 5 bytes
    }

    public function testRoundTrip(): void
    {
        foreach (['', 'hello', 'ünïcødé 🔥'] as $value) {
            $encoded = $this->type->encode($value);
            self::assertSame($value, $this->type->decode($encoded)[0]);
        }
    }

    public function testBytesAreLengthPrefixed(): void
    {
        self::assertSame("\x00\x00\x00\x03" . 'abc', $this->type->encode('abc'));
    }

    public function testDecodeRejectsTruncatedPayload(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x00\x05" . 'ab');
    }
}

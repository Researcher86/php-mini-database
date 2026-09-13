<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\BlobType;
use PhpMiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class BlobTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new BlobType();
    }

    public function testCastAcceptsBinaryStringsOnly(): void
    {
        self::assertSame("\x00\x01\xff", $this->type->cast("\x00\x01\xff"));
    }

    public function testCastRejectsNonStrings(): void
    {
        $thrown = 0;

        foreach ([42, 3.14, true, ['a']] as $value) {
            try {
                $this->type->cast($value);
            } catch (TypeException) {
                $thrown++;
            }
        }

        self::assertSame(4, $thrown);
    }

    public function testRoundTrip(): void
    {
        foreach (['', "\x00", "\x00\xff\x00", str_repeat("\x42", 1000)] as $value) {
            $encoded = $this->type->encode($value);
            self::assertSame($value, $this->type->decode($encoded)[0]);
        }
    }

    public function testBytesAreLengthPrefixed(): void
    {
        self::assertSame("\x00\x00\x00\x02" . "\x00\xff", $this->type->encode("\x00\xff"));
    }

    public function testDecodeRejectsTruncatedPayload(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode("\x00\x00\x00\x05" . "\x00\x01");
    }
}

<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Network\Protocol;

use MiniDatabase\Network\Protocol\ValueCodec;
use MiniDatabase\Schema\Type\IntType;
use MiniDatabase\Schema\Type\VarcharType;
use PHPUnit\Framework\TestCase;

final class ValueCodecTest extends TestCase
{
    private ValueCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new ValueCodec();
    }

    public function testEncodeValueCastsThenEncodes(): void
    {
        self::assertSame("\x80\x00\x00\x2a", $this->codec->encodeValue(new IntType(), '42'));
    }

    public function testEncodeValuePreservesTypeBytesForProtocol(): void
    {
        $varchar = new VarcharType(16);
        $bytes = $this->codec->encodeValue($varchar, 'hello');

        self::assertSame("\x00\x00\x00\x05" . 'hello', $bytes);
    }

    public function testDecodeValueReadsAndAdvances(): void
    {
        $varchar = new VarcharType(16);
        $encoded = $this->codec->encodeValue(new IntType(), 7)
            . $this->codec->encodeValue($varchar, 'hi');

        [$value, $offset] = $this->codec->decodeValue($varchar, $encoded, 4);

        self::assertSame('hi', $value);
        self::assertSame(10, $offset);
    }

    public function testEncodedValuesStitchBackIntoARow(): void
    {
        $int = new IntType();
        $varchar = new VarcharType(16);
        $values = [123, 'abc'];
        $types = [$int, $varchar];

        $wired = $this->codec->encodeValue($int, $values[0])
            . $this->codec->encodeValue($varchar, $values[1]);

        $offset = 0;
        $decoded = [];
        foreach ($types as $type) {
            [$value, $offset] = $this->codec->decodeValue($type, $wired, $offset);
            $decoded[] = $value;
        }

        self::assertSame($values, $decoded);
    }
}

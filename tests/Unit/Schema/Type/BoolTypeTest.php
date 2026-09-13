<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\BoolType;
use PhpMiniDatabase\Schema\Type\Type;
use PHPUnit\Framework\TestCase;

final class BoolTypeTest extends TestCase
{
    private Type $type;

    protected function setUp(): void
    {
        $this->type = new BoolType();
    }

    public function testCastAcceptsBoolsAndCommonSpellings(): void
    {
        self::assertTrue($this->type->cast(true));
        self::assertFalse($this->type->cast(false));
        self::assertTrue($this->type->cast(1));
        self::assertFalse($this->type->cast(0));
        self::assertTrue($this->type->cast('true'));
        self::assertFalse($this->type->cast('FALSE'));
        self::assertTrue($this->type->cast('1'));
        self::assertFalse($this->type->cast('0'));
    }

    public function testCastRejectsIncompatibleValues(): void
    {
        $thrown = 0;

        foreach ([2, 3.5, 'maybe', []] as $value) {
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
        self::assertTrue($this->type->decode($this->type->encode(true))[0]);
        self::assertFalse($this->type->decode($this->type->encode(false))[0]);
    }

    public function testBytesAreOneByte(): void
    {
        self::assertSame("\x01", $this->type->encode(true));
        self::assertSame("\x00", $this->type->encode(false));
    }

    public function testDecodeRejectsEmptyBuffer(): void
    {
        $this->expectException(TypeException::class);
        $this->type->decode('');
    }
}

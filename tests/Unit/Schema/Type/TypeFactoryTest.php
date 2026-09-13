<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Schema\Type;

use MiniDatabase\Exception\TypeException;
use MiniDatabase\Schema\Type\BigIntType;
use MiniDatabase\Schema\Type\BlobType;
use MiniDatabase\Schema\Type\BoolType;
use MiniDatabase\Schema\Type\DateTimeType;
use MiniDatabase\Schema\Type\DateType;
use MiniDatabase\Schema\Type\DecimalType;
use MiniDatabase\Schema\Type\IntType;
use MiniDatabase\Schema\Type\Type;
use MiniDatabase\Schema\Type\TypeFactory;
use MiniDatabase\Schema\Type\VarcharType;
use PHPUnit\Framework\TestCase;

final class TypeFactoryTest extends TestCase
{
    private TypeFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new TypeFactory();
    }

    /**
     * The property that matters is the round trip: whatever a type calls
     * itself, the factory must give that same type back. It is what lets the
     * catalog store `$type->name()` and read it again without a mapping of
     * its own.
     */
    public function testEveryTypeNameRoundTrips(): void
    {
        $types = [
            new IntType(),
            new BigIntType(),
            new VarcharType(255),
            new DecimalType(10, 2),
            new BoolType(),
            new DateType(),
            new DateTimeType(),
            new BlobType(),
        ];

        foreach ($types as $type) {
            self::assertEquals($type, $this->factory->fromName($type->name()));
        }
    }

    public function testNamesAreCaseAndSpaceInsensitive(): void
    {
        self::assertEquals(new IntType(), $this->factory->fromName('int'));
        self::assertEquals(new VarcharType(64), $this->factory->fromName('varchar (64)'));
        self::assertEquals(new DecimalType(8, 3), $this->factory->fromName(' Decimal( 8 , 3 ) '));
    }

    public function testUnknownAndMalformedNamesAreRejected(): void
    {
        $names = ['TEXT', 'VARCHAR', 'VARCHAR()', 'VARCHAR(255)EXTRA', 'DECIMAL(10)', ''];
        $rejected = 0;

        foreach ($names as $name) {
            try {
                $this->factory->fromName($name);
            } catch (TypeException) {
                $rejected++;
            }
        }

        self::assertCount($rejected, $names);
    }

    public function testMalformedParametersReachTheTypesOwnValidation(): void
    {
        $this->expectException(TypeException::class);
        $this->factory->fromName('VARCHAR(0)');
    }

    public function testCodesMapBackToADecodingType(): void
    {
        $types = [
            new IntType(),
            new BigIntType(),
            new VarcharType(255),
            new BoolType(),
            new DateType(),
            new DateTimeType(),
            new BlobType(),
        ];

        foreach ($types as $type) {
            $decoder = $this->factory->fromCode($type->code());

            self::assertInstanceOf($type::class, $decoder);
        }
    }

    /**
     * A type rebuilt from its code must read bytes written by the declared
     * type — that is the whole reason fromCode() exists. VARCHAR is the
     * interesting case: the two instances disagree about the maximum length
     * and must still agree about the bytes.
     */
    public function testATypeRebuiltFromItsCodeDecodesTheDeclaredTypesBytes(): void
    {
        $declared = new VarcharType(8);
        $decoder = $this->factory->fromCode(VarcharType::CODE);

        self::assertSame('abc', $decoder->decode($declared->encode('abc'))[0]);
    }

    /**
     * DECIMAL's scale decides where the point goes and the code does not
     * carry it, so the factory refuses rather than returning a type that
     * would silently decode 12.34 as 1234.
     */
    public function testDecimalCannotBeRebuiltFromItsCode(): void
    {
        $this->expectException(TypeException::class);
        $this->factory->fromCode(DecimalType::CODE);
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->expectException(TypeException::class);
        $this->factory->fromCode(99);
    }

    /**
     * Codes are the wire's identity for a type and are never reused, so the
     * fact that they are all distinct is worth asserting rather than
     * assuming.
     */
    public function testCodesAreUnique(): void
    {
        /** @var list<Type> $types */
        $types = [
            new IntType(),
            new BigIntType(),
            new VarcharType(255),
            new DecimalType(10, 2),
            new BoolType(),
            new DateType(),
            new DateTimeType(),
            new BlobType(),
        ];

        $codes = array_map(static fn (Type $type): int => $type->code(), $types);

        self::assertSame($codes, array_unique($codes));
    }
}

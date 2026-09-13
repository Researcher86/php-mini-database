<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Storage;

use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\BoolType;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\Type;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Storage\RecordSerializer;
use PHPUnit\Framework\TestCase;

final class RecordSerializerTest extends TestCase
{
    private RecordSerializer $serializer;

    /** @var list<Type> */
    private array $types;

    protected function setUp(): void
    {
        $this->serializer = new RecordSerializer();
        $this->types = [new IntType(), new VarcharType(32), new BoolType()];
    }

    public function testRoundTripWithoutNulls(): void
    {
        $values = [42, 'hello', true];
        $record = $this->serializer->serialize($values, $this->types);

        self::assertSame($values, $this->serializer->deserialize($record, $this->types));
    }

    public function testRoundTripWithNullsMixedIn(): void
    {
        $values = [42, null, false];
        $record = $this->serializer->serialize($values, $this->types);

        self::assertSame($values, $this->serializer->deserialize($record, $this->types));
    }

    public function testRoundTripWithAllNulls(): void
    {
        $values = [null, null, null];
        $record = $this->serializer->serialize($values, $this->types);

        self::assertSame($values, $this->serializer->deserialize($record, $this->types));
    }

    public function testNullBitmapIsCompact(): void
    {
        $nineTypes = \array_map(static fn (): Type => new IntType(), range(1, 9));
        $values = \array_fill(0, 9, 1);
        $values[8] = null;

        $record = $this->serializer->serialize($values, $nineTypes);

        // 9 columns -> 2 bitmap bytes, 8 non-null INTs.
        self::assertSame(2 + 8 * 4, strlen($record));
    }

    public function testFirstByteCarriesFirstEightColumns(): void
    {
        $types = \array_map(static fn (): Type => new IntType(), range(1, 8));
        $values = [null, 1, null, 3, 4, 5, 6, 7];

        $record = $this->serializer->serialize($values, $types);

        // Bits 0 and 2 set -> 0b101 = 0x05 in the first bitmap byte.
        self::assertSame("\x05", substr($record, 0, 1));
    }

    public function testSerializeRejectsMismatchedCounts(): void
    {
        $this->expectException(TypeException::class);
        $this->serializer->serialize([1, 2], $this->types);
    }

    public function testDeserializeRejectsTruncatedRecord(): void
    {
        $this->expectException(StorageException::class);
        $this->serializer->deserialize('', $this->types);
    }
}

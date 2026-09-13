<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Storage;

use MiniDatabase\Exception\StorageException;
use MiniDatabase\Storage\RecordId;
use PHPUnit\Framework\TestCase;

final class RecordIdTest extends TestCase
{
    public function testStringFormIsPageColonSlot(): void
    {
        self::assertSame('3:17', (string) new RecordId(3, 17));
    }

    public function testStringFormRoundTrips(): void
    {
        $id = new RecordId(42, 7);

        self::assertTrue($id->equals(RecordId::fromString((string) $id)));
    }

    public function testEqualityIsByValue(): void
    {
        self::assertTrue((new RecordId(1, 2))->equals(new RecordId(1, 2)));
        self::assertFalse((new RecordId(1, 2))->equals(new RecordId(2, 1)));
    }

    public function testMalformedStringsAreRejected(): void
    {
        $malformed = ['', '3', '3:', ':7', 'a:b', '3:7:1', '-1:0'];
        $rejected = 0;

        foreach ($malformed as $id) {
            try {
                RecordId::fromString($id);
            } catch (StorageException) {
                $rejected++;
            }
        }

        self::assertCount($rejected, $malformed);
    }
}

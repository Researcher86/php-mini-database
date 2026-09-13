<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Unit\Infrastructure;

use MiniDatabase\Exception\StorageException;
use MiniDatabase\Infrastructure\Path;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    public function testOrdinaryIdentifiersAreAccepted(): void
    {
        foreach (['users', '_internal', 'Order_Items_2024', 'a'] as $name) {
            self::assertSame($name, Path::identifier($name));
        }
    }

    /**
     * The list is the attack surface: every one of these is a name SQL could
     * carry, and each would become a path if it got through.
     */
    public function testDangerousAndMalformedIdentifiersAreRejected(): void
    {
        $rejected = [
            '',
            '..',
            '../etc/passwd',
            'users/../../secret',
            'with space',
            'with-dash',
            'with.dot',
            '1leading_digit',
            "null\x00byte",
            str_repeat('x', 65),
        ];

        $caught = 0;

        foreach ($rejected as $name) {
            try {
                Path::identifier($name);
            } catch (StorageException) {
                $caught++;
            }
        }

        self::assertCount($caught, $rejected);
    }

    public function testJoinBuildsAPathFromSegments(): void
    {
        self::assertSame('/data/mydb/tables/users/heap.dat', Path::join('/data/mydb', 'tables', 'users', 'heap.dat'));
    }

    public function testJoinDoesNotDoubleUpTheSeparator(): void
    {
        self::assertSame('/data/mydb/catalog.json', Path::join('/data/mydb/', 'catalog.json'));
    }

    public function testJoinRefusesSegmentsThatCouldEscapeTheDirectory(): void
    {
        $rejected = ['', '..', 'a/b', 'a\\b'];
        $caught = 0;

        foreach ($rejected as $segment) {
            try {
                Path::join('/data', $segment);
            } catch (StorageException) {
                $caught++;
            }
        }

        self::assertCount($caught, $rejected);
    }
}

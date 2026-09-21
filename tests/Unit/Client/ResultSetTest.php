<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Client;

use PhpMiniDatabase\Client\ResultSet;
use PHPUnit\Framework\TestCase;

final class ResultSetTest extends TestCase
{
    public function testColumnsAndCount(): void
    {
        $result = new ResultSet(['id', 'name'], [[1, 'Ann'], [2, 'Bob']]);

        self::assertSame(['id', 'name'], $result->columns());
        self::assertCount(2, $result);
    }

    public function testFetchReturnsOneAssociativeRowAtATimeThenNull(): void
    {
        $result = new ResultSet(['id', 'name'], [[1, 'Ann'], [2, 'Bob']]);

        self::assertSame(['id' => 1, 'name' => 'Ann'], $result->fetch());
        self::assertSame(['id' => 2, 'name' => 'Bob'], $result->fetch());
        self::assertNull($result->fetch());
    }

    public function testFetchAllReturnsEveryRowRegardlessOfTheFetchCursor(): void
    {
        $result = new ResultSet(['id'], [[1], [2], [3]]);
        $result->fetch();

        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $result->fetchAll());
    }

    public function testIteratingWithForeachDoesNotConsumeTheFetchCursor(): void
    {
        $result = new ResultSet(['id'], [[1], [2]]);

        $seen = [];

        foreach ($result as $row) {
            $seen[] = $row;
        }

        self::assertSame([['id' => 1], ['id' => 2]], $seen);
        self::assertSame(['id' => 1], $result->fetch());
    }

    public function testIteratingTwiceGivesTheSameRowsBothTimes(): void
    {
        $result = new ResultSet(['id'], [[1], [2]]);

        $first = iterator_to_array($result, false);
        $second = iterator_to_array($result, false);

        self::assertSame($first, $second);
    }

    public function testAffectedRowsRecognizesTheDmlConvention(): void
    {
        $result = new ResultSet(['affected_rows'], [[3]]);

        self::assertSame(3, $result->affectedRows());
    }

    public function testAffectedRowsIsNullForARealSelectResult(): void
    {
        $result = new ResultSet(['id'], [[1]]);

        self::assertNull($result->affectedRows());
    }

    public function testAffectedRowsIsNullForAnEmptyDdlResult(): void
    {
        $result = new ResultSet([], []);

        self::assertNull($result->affectedRows());
        self::assertNull($result->fetch());
        self::assertSame([], $result->fetchAll());
        self::assertCount(0, $result);
    }
}

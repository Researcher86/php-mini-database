<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Row;
use PHPUnit\Framework\TestCase;

final class RowTest extends TestCase
{
    public function testGetReturnsAKnownColumnsValue(): void
    {
        $row = new Row(['id' => 1, 'email' => 'a@x.com']);

        self::assertSame(1, $row->get('id'));
        self::assertSame('a@x.com', $row->get('email'));
    }

    public function testHasDistinguishesAnExplicitNullFromAMissingColumn(): void
    {
        $row = new Row(['nickname' => null]);

        self::assertTrue($row->has('nickname'));
        self::assertFalse($row->has('missing'));
    }

    public function testGettingAMissingColumnThrows(): void
    {
        $row = new Row(['id' => 1]);

        $this->expectException(SchemaException::class);
        $row->get('missing');
    }

    public function testColumnNamesAndToArrayReflectWhatWasGiven(): void
    {
        $values = ['id' => 1, 'email' => 'a@x.com'];
        $row = new Row($values);

        self::assertSame(['id', 'email'], $row->columnNames());
        self::assertSame($values, $row->toArray());
    }
}

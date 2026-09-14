<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema;

use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testAColumnWithNoDefaultReportsSo(): void
    {
        $column = new Column('age', new IntType());

        self::assertFalse($column->hasDefault());
    }

    public function testWithDefaultCastsThroughTheColumnsType(): void
    {
        $column = (new Column('age', new IntType()))->withDefault('30');

        self::assertTrue($column->hasDefault());
        self::assertSame(30, $column->defaultValue());
    }

    /**
     * NULL is a legitimate default for a nullable column, and has to be
     * distinguishable from "no default was declared at all".
     */
    public function testANullDefaultIsDistinctFromNoDefault(): void
    {
        $column = (new Column('nickname', new VarcharType(50)))->withDefault(null);

        self::assertTrue($column->hasDefault());
        self::assertNull($column->defaultValue());
    }

    public function testRawDefaultIsWhatWasGivenNotTheCastForm(): void
    {
        $column = (new Column('age', new IntType()))->withDefault('30');

        self::assertSame('30', $column->rawDefault());
        self::assertSame(30, $column->defaultValue());
    }

    public function testColumnNamesAreValidatedAsIdentifiers(): void
    {
        $this->expectException(StorageException::class);
        new Column('has space', new IntType());
    }
}

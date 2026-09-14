<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\BoolType;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    private function usersTable(): Table
    {
        return new Table(
            'users',
            [
                new Column('id', new IntType(), notNull: true),
                new Column('email', new VarcharType(255), notNull: true),
                (new Column('age', new IntType()))->withDefault(0),
                (new Column('active', new BoolType(), notNull: true))->withDefault(true),
            ],
            [
                new PrimaryKey(['id']),
                UniqueConstraint::on('users', ['email']),
            ],
        );
    }

    public function testColumnLookupByName(): void
    {
        $table = $this->usersTable();

        self::assertTrue($table->hasColumn('email'));
        self::assertFalse($table->hasColumn('missing'));
        self::assertSame('email', $table->column('email')->name);
        self::assertSame(['id', 'email', 'age', 'active'], $table->columnNames());
    }

    public function testUnknownColumnLookupThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->usersTable()->column('missing');
    }

    public function testPrimaryKeyIsFoundAmongTheConstraints(): void
    {
        $pk = $this->usersTable()->primaryKey();

        self::assertNotNull($pk);
        self::assertSame(['id'], $pk->columns());
    }

    public function testATableWithoutAPrimaryKeyReportsNone(): void
    {
        $table = new Table('logs', [new Column('message', new VarcharType(255))]);

        self::assertNull($table->primaryKey());
    }

    public function testATableNeedsAtLeastOneColumn(): void
    {
        $this->expectException(SchemaException::class);
        new Table('empty', []);
    }

    public function testDuplicateColumnNamesAreRejected(): void
    {
        $this->expectException(SchemaException::class);
        new Table('bad', [new Column('id', new IntType()), new Column('id', new VarcharType(10))]);
    }

    public function testAConstraintNamingAnUnknownColumnIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        new Table('bad', [new Column('id', new IntType())], [new PrimaryKey(['missing'])]);
    }

    public function testAtMostOnePrimaryKeyIsAllowed(): void
    {
        $this->expectException(SchemaException::class);
        new Table(
            'bad',
            [new Column('id', new IntType(), notNull: true), new Column('code', new IntType(), notNull: true)],
            [new PrimaryKey(['id']), new PrimaryKey(['code'])],
        );
    }

    public function testAPrimaryKeyColumnMustBeDeclaredNotNull(): void
    {
        $this->expectException(SchemaException::class);
        new Table('bad', [new Column('id', new IntType())], [new PrimaryKey(['id'])]);
    }

    public function testDuplicateConstraintNamesAreRejected(): void
    {
        $this->expectException(SchemaException::class);
        new Table(
            'bad',
            [new Column('email', new VarcharType(255))],
            [new UniqueConstraint('dup', ['email']), new UniqueConstraint('dup', ['email'])],
        );
    }

    public function testAnIndexNamingAnUnknownColumnIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        new Table('bad', [new Column('id', new IntType())], indexes: [new IndexDefinition('idx', ['missing'])]);
    }

    public function testDuplicateIndexNamesAreRejected(): void
    {
        $this->expectException(SchemaException::class);
        new Table(
            'bad',
            [new Column('email', new VarcharType(255))],
            indexes: [new IndexDefinition('dup', ['email']), new IndexDefinition('dup', ['email'])],
        );
    }

    public function testAForeignKeyIsAcceptedWithoutItsOtherTableBeingKnown(): void
    {
        // Table validates itself only; the other table's existence is
        // Catalog's concern, checked when the table is added to one.
        $table = new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['id'])],
        );

        self::assertCount(1, $table->constraints());
    }

    public function testValuesFromRowOrdersByColumnAndFillsDefaults(): void
    {
        $row = new Row(['id' => 1, 'email' => 'a@x.com']);

        self::assertSame([1, 'a@x.com', 0, true], $this->usersTable()->valuesFromRow($row));
    }

    public function testValuesFromRowCastsThroughTheColumnsType(): void
    {
        $row = new Row(['id' => '7', 'email' => 'a@x.com', 'age' => '42']);

        self::assertSame([7, 'a@x.com', 42, true], $this->usersTable()->valuesFromRow($row));
    }

    public function testValuesFromRowRejectsAnUnknownColumn(): void
    {
        $row = new Row(['id' => 1, 'email' => 'a@x.com', 'nickname' => 'x']);

        $this->expectException(SchemaException::class);
        $this->usersTable()->valuesFromRow($row);
    }

    public function testValuesFromRowRejectsNullForANotNullColumnWithNoDefault(): void
    {
        $row = new Row(['id' => 1]);

        $this->expectException(ConstraintViolationException::class);
        $this->usersTable()->valuesFromRow($row);
    }

    public function testValuesFromRowRejectsAnExplicitNullForANotNullColumn(): void
    {
        $row = new Row(['id' => 1, 'email' => null]);

        $this->expectException(ConstraintViolationException::class);
        $this->usersTable()->valuesFromRow($row);
    }

    public function testRowFromValuesZipsWithColumnNames(): void
    {
        $row = $this->usersTable()->rowFromValues([1, 'a@x.com', 30, false]);

        self::assertSame(
            ['id' => 1, 'email' => 'a@x.com', 'age' => 30, 'active' => false],
            $row->toArray(),
        );
    }

    public function testARowRoundTripsThroughSerializeAndDeserialize(): void
    {
        $table = $this->usersTable();
        $row = new Row(['id' => 1, 'email' => 'a@x.com', 'age' => 30, 'active' => true]);

        $record = $table->serializeRow($row);

        self::assertSame($row->toArray(), $table->deserializeRow($record)->toArray());
    }
}

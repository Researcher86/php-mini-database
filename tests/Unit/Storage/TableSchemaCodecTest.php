<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Storage;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\IndexDefinition;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\DateType;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Storage\TableSchemaCodec;
use PHPUnit\Framework\TestCase;

final class TableSchemaCodecTest extends TestCase
{
    private TableSchemaCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new TableSchemaCodec();
    }

    private function assertTableEquals(Table $expected, Table $actual): void
    {
        self::assertEquals($this->codec->encode($expected), $this->codec->encode($actual));
    }

    public function testATableWithNoConstraintsOrIndexesRoundTrips(): void
    {
        $table = new Table('logs', [new Column('message', new VarcharType(255))]);

        $this->assertTableEquals($table, $this->codec->decode($this->codec->encode($table)));
    }

    public function testColumnFlagsAndDefaultsRoundTrip(): void
    {
        $table = new Table('users', [
            new Column('id', new IntType(), notNull: true),
            (new Column('age', new IntType()))->withDefault(0),
            (new Column('nickname', new VarcharType(50)))->withDefault(null),
        ]);

        $decoded = $this->codec->decode($this->codec->encode($table));

        self::assertTrue($decoded->column('id')->notNull);
        self::assertTrue($decoded->column('age')->hasDefault());
        self::assertSame(0, $decoded->column('age')->defaultValue());
        self::assertTrue($decoded->column('nickname')->hasDefault());
        self::assertNull($decoded->column('nickname')->defaultValue());
    }

    /**
     * A DATE default is kept as the string it was given, not the
     * DateTimeImmutable it casts to — because JSON has no native date type
     * to hold the canonical form in.
     */
    public function testADateDefaultRoundTripsAsAString(): void
    {
        $table = new Table('events', [
            (new Column('starts_on', new DateType()))->withDefault('2024-01-01'),
        ]);

        $encoded = $this->codec->encode($table);

        self::assertSame('2024-01-01', $encoded['columns'][0]['default']);

        $decoded = $this->codec->decode($encoded);

        self::assertSame('2024-01-01', $decoded->column('starts_on')->defaultValue()->format('Y-m-d'));
    }

    public function testAColumnWithNoDefaultHasNoDefaultKeyInTheEncodedForm(): void
    {
        $table = new Table('users', [new Column('id', new IntType())]);

        self::assertArrayNotHasKey('default', $this->codec->encode($table)['columns'][0]);
    }

    public function testPrimaryKeyRoundTrips(): void
    {
        $table = new Table(
            'users',
            [new Column('id', new IntType(), notNull: true)],
            [new PrimaryKey(['id'])],
        );

        $decoded = $this->codec->decode($this->codec->encode($table));

        self::assertSame(['id'], $decoded->primaryKey()?->columns());
    }

    public function testUniqueConstraintRoundTrips(): void
    {
        $table = new Table(
            'users',
            [new Column('email', new VarcharType(255))],
            [UniqueConstraint::on('users', ['email'])],
        );

        $decoded = $this->codec->decode($this->codec->encode($table));

        self::assertEquals($table->constraints(), $decoded->constraints());
    }

    public function testForeignKeyRoundTripsIncludingActions(): void
    {
        $table = new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['id'], ReferentialAction::CASCADE, ReferentialAction::SET_NULL)],
        );

        $decoded = $this->codec->decode($this->codec->encode($table));

        self::assertEquals($table->constraints(), $decoded->constraints());
    }

    public function testCheckConstraintRoundTrips(): void
    {
        $table = new Table(
            'users',
            [new Column('age', new IntType())],
            [CheckConstraint::on('users', 'age >= 0', ['age'])],
        );

        $decoded = $this->codec->decode($this->codec->encode($table));

        self::assertEquals($table->constraints(), $decoded->constraints());
    }

    public function testIndexesRoundTrip(): void
    {
        $table = new Table(
            'users',
            [new Column('email', new VarcharType(255))],
            indexes: [new IndexDefinition('idx_users_email', ['email'], unique: true)],
        );

        $decoded = $this->codec->decode($this->codec->encode($table));

        self::assertEquals($table->indexes(), $decoded->indexes());
    }

    public function testAnEncodedTableSurvivesAJsonRoundTrip(): void
    {
        $table = new Table(
            'users',
            [new Column('id', new IntType(), notNull: true), new Column('email', new VarcharType(255))],
            [new PrimaryKey(['id']), UniqueConstraint::on('users', ['email'])],
            [new IndexDefinition('idx_users_email', ['email'])],
        );

        $json = json_encode($this->codec->encode($table), JSON_THROW_ON_ERROR);
        $decoded = $this->codec->decode(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        $this->assertTableEquals($table, $decoded);
    }

    public function testAnUnknownConstraintTypeIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->codec->decode([
            'name' => 'users',
            'columns' => [['name' => 'id', 'type' => 'INT', 'not_null' => true]],
            'constraints' => [['type' => 'MYSTERY', 'columns' => ['id']]],
            'indexes' => [],
        ]);
    }

    public function testAMissingRequiredFieldIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->codec->decode(['columns' => []]);
    }
}

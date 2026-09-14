<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Storage;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Column;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\Table;
use PhpMiniDatabase\Schema\Type\IntType;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Storage\Catalog;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    use TemporaryDirectory;

    private Catalog $catalog;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->catalog = Catalog::open($this->path('mydb'));
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function usersTable(): Table
    {
        return new Table(
            'users',
            [new Column('id', new IntType(), notNull: true), new Column('email', new VarcharType(255))],
            [new PrimaryKey(['id']), UniqueConstraint::on('users', ['email'])],
        );
    }

    public function testANewCatalogHasNoTables(): void
    {
        self::assertSame([], $this->catalog->tableNames());
        self::assertFalse($this->catalog->hasTable('users'));
    }

    public function testACreatedTableCanBeReadBack(): void
    {
        $this->catalog->createTable($this->usersTable());

        self::assertTrue($this->catalog->hasTable('users'));
        self::assertSame(['users'], $this->catalog->tableNames());
        self::assertSame(['id', 'email'], $this->catalog->table('users')->columnNames());
    }

    public function testTableNamesAreSorted(): void
    {
        $this->catalog->createTable(new Table('zebras', [new Column('id', new IntType())]));
        $this->catalog->createTable(new Table('apples', [new Column('id', new IntType())]));

        self::assertSame(['apples', 'zebras'], $this->catalog->tableNames());
    }

    public function testCreatingADuplicateTableIsRejected(): void
    {
        $this->catalog->createTable($this->usersTable());

        $this->expectException(SchemaException::class);
        $this->catalog->createTable($this->usersTable());
    }

    public function testReadingAnUnknownTableIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->catalog->table('missing');
    }

    public function testDroppingATableRemovesIt(): void
    {
        $this->catalog->createTable($this->usersTable());

        $this->catalog->dropTable('users');

        self::assertFalse($this->catalog->hasTable('users'));
    }

    public function testDroppingAnUnknownTableIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->catalog->dropTable('missing');
    }

    public function testATableSurvivesReopeningTheCatalog(): void
    {
        $this->catalog->createTable($this->usersTable());

        $reopened = Catalog::open($this->path('mydb'));

        self::assertSame(['id', 'email'], $reopened->table('users')->columnNames());
    }

    public function testAForeignKeyToAnExistingTableIsAccepted(): void
    {
        $this->catalog->createTable($this->usersTable());

        $this->catalog->createTable(new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['id'])],
        ));

        self::assertTrue($this->catalog->hasTable('orders'));
    }

    public function testAForeignKeyToAMissingTableIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->catalog->createTable(new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['id'])],
        ));
    }

    public function testAForeignKeyToAColumnThatIsNotUniqueIsRejected(): void
    {
        $this->catalog->createTable(new Table('users', [new Column('id', new IntType())]));

        $this->expectException(SchemaException::class);
        $this->catalog->createTable(new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['id'])],
        ));
    }

    public function testAForeignKeyToAnUnknownColumnIsRejected(): void
    {
        $this->catalog->createTable($this->usersTable());

        $this->expectException(SchemaException::class);
        $this->catalog->createTable(new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['missing'])],
        ));
    }

    /**
     * A self-referencing foreign key has to be checked against the table
     * being created, since it cannot already be in the catalog.
     */
    public function testASelfReferencingForeignKeyIsAccepted(): void
    {
        $this->catalog->createTable(new Table(
            'categories',
            [new Column('id', new IntType(), notNull: true), new Column('parent_id', new IntType())],
            [new PrimaryKey(['id']), ForeignKey::on('categories', ['parent_id'], 'categories', ['id'])],
        ));

        self::assertTrue($this->catalog->hasTable('categories'));
    }

    public function testDroppingATableWithADependentForeignKeyIsRejected(): void
    {
        $this->catalog->createTable($this->usersTable());
        $this->catalog->createTable(new Table(
            'orders',
            [new Column('user_id', new IntType())],
            [ForeignKey::on('orders', ['user_id'], 'users', ['id'])],
        ));

        $this->expectException(SchemaException::class);
        $this->catalog->dropTable('users');
    }
}

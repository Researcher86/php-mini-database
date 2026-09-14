<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\TableBuilder;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PhpMiniDatabase\Schema\Type\VarcharType;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class TableBuilderTest extends TestCase
{
    private TableBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TableBuilder();
    }

    private function createTable(string $sql): CreateTableStatement
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(CreateTableStatement::class, $statement);

        return $statement;
    }

    public function testColumnTypesAreResolved(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE t (id INT, name VARCHAR(50))'));

        self::assertEquals(new VarcharType(50), $table->column('name')->type);
    }

    public function testInlinePrimaryKeyBecomesATableConstraint(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE t (id INT PRIMARY KEY)'));

        self::assertSame(['id'], $table->primaryKey()?->columns());
    }

    public function testInlineUniqueBecomesAUniqueConstraint(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE t (email VARCHAR(255) UNIQUE)'));

        self::assertEquals(UniqueConstraint::on('t', ['email']), $table->constraints()[0]);
    }

    public function testInlineCheckIsPrintedBackToSql(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE t (age INT CHECK (age >= 0))'));

        $check = $table->constraints()[0];
        self::assertInstanceOf(CheckConstraint::class, $check);
        self::assertSame('(age >= 0)', $check->expression);
    }

    public function testInlineForeignKeyShorthand(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE orders (user_id INT REFERENCES users (id))'));

        self::assertEquals(
            ForeignKey::on('orders', ['user_id'], 'users', ['id']),
            $table->constraints()[0],
        );
    }

    public function testLiteralDefaultIsEvaluated(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE t (age INT DEFAULT 0)'));

        self::assertSame(0, $table->column('age')->defaultValue());
    }

    public function testDynamicDefaultIsRejected(): void
    {
        $this->expectException(ExecutionException::class);
        $this->builder->build($this->createTable('CREATE TABLE t (created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'));
    }

    public function testTableLevelPrimaryKey(): void
    {
        $table = $this->builder->build(
            $this->createTable('CREATE TABLE t (a INT NOT NULL, b INT NOT NULL, PRIMARY KEY (a, b))'),
        );

        self::assertSame(['a', 'b'], $table->primaryKey()?->columns());
    }

    public function testTableLevelUniqueWithExplicitName(): void
    {
        $table = $this->builder->build(
            $this->createTable('CREATE TABLE t (email VARCHAR(255), CONSTRAINT uq_e UNIQUE (email))'),
        );

        self::assertSame('uq_e', $table->constraints()[0]->name());
    }

    public function testTableLevelForeignKeyWithActions(): void
    {
        $table = $this->builder->build($this->createTable(
            'CREATE TABLE orders (user_id INT, FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)',
        ));

        $fk = $table->constraints()[0];
        self::assertInstanceOf(ForeignKey::class, $fk);
        self::assertSame(ReferentialAction::CASCADE, $fk->onDelete);
    }

    public function testTableLevelCheckWithoutAName(): void
    {
        $table = $this->builder->build($this->createTable('CREATE TABLE t (age INT, CHECK (age >= 0))'));

        self::assertInstanceOf(CheckConstraint::class, $table->constraints()[0]);
    }

    public function testTheFullPlanExampleBuildsACoherentTable(): void
    {
        $table = $this->builder->build($this->createTable(
            "CREATE TABLE users (
                id INT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                age INT DEFAULT 0 CHECK (age >= 0)
            )",
        ));

        self::assertSame(['id'], $table->primaryKey()?->columns());
        self::assertTrue($table->column('email')->notNull);
        self::assertSame(0, $table->column('age')->defaultValue());
        self::assertCount(3, $table->constraints());
    }
}

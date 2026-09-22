<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
use PhpMiniDatabase\Sql\Ast\AlterAction\AddColumn;
use PhpMiniDatabase\Sql\Ast\AlterAction\DropColumn;
use PhpMiniDatabase\Sql\Ast\AlterTableStatement;
use PhpMiniDatabase\Sql\Ast\CreateIndexStatement;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Ast\DropIndexStatement;
use PhpMiniDatabase\Sql\Ast\DropTableStatement;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\TableConstraint\CheckDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\ForeignKeyDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\PrimaryKeyDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\UniqueDefinition;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ParserDdlTest extends TestCase
{
    private function createTable(string $sql): CreateTableStatement
    {
        $statement = Parser::parseOne($sql);
        self::assertInstanceOf(CreateTableStatement::class, $statement);

        return $statement;
    }

    public function testColumnTypesAreKeptAsWritten(): void
    {
        $table = $this->createTable('CREATE TABLE t (a INT, b VARCHAR(255), c DECIMAL(10,2))');

        self::assertSame('INT', $table->columns[0]->type);
        self::assertSame('VARCHAR(255)', $table->columns[1]->type);
        self::assertSame('DECIMAL(10,2)', $table->columns[2]->type);
    }

    public function testInlineNotNull(): void
    {
        $table = $this->createTable('CREATE TABLE t (id INT NOT NULL)');

        self::assertTrue($table->columns[0]->notNull);
    }

    public function testInlinePrimaryKey(): void
    {
        $table = $this->createTable('CREATE TABLE t (id INT PRIMARY KEY)');

        self::assertTrue($table->columns[0]->primaryKey);
    }

    public function testInlineUnique(): void
    {
        $table = $this->createTable('CREATE TABLE t (email VARCHAR(255) UNIQUE)');

        self::assertTrue($table->columns[0]->unique);
    }

    public function testInlineDefaultLiteral(): void
    {
        $table = $this->createTable('CREATE TABLE t (age INT DEFAULT 0)');

        self::assertEquals(new Literal(0), $table->columns[0]->default);
    }

    public function testInlineDefaultFunctionCall(): void
    {
        $table = $this->createTable('CREATE TABLE t (created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

        self::assertEquals(new FunctionCall('CURRENT_TIMESTAMP'), $table->columns[0]->default);
    }

    public function testInlineCheck(): void
    {
        $table = $this->createTable('CREATE TABLE t (age INT CHECK (age >= 0))');

        self::assertNotNull($table->columns[0]->check);
    }

    public function testInlineForeignKeyShorthand(): void
    {
        $table = $this->createTable('CREATE TABLE orders (user_id INT REFERENCES users (id))');

        $fk = $table->columns[0]->foreignKey;
        self::assertInstanceOf(ForeignKeyDefinition::class, $fk);
        self::assertSame(['user_id'], $fk->columns);
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns);
    }

    public function testInlineForeignKeyWithActions(): void
    {
        $table = $this->createTable(
            'CREATE TABLE orders (user_id INT REFERENCES users (id) ON DELETE CASCADE ON UPDATE SET NULL)',
        );

        $fk = $table->columns[0]->foreignKey;
        self::assertInstanceOf(ForeignKeyDefinition::class, $fk);
        self::assertSame(ReferentialAction::CASCADE, $fk->onDelete);
        self::assertSame(ReferentialAction::SET_NULL, $fk->onUpdate);
    }

    public function testModifiersCanAppearInAnyOrder(): void
    {
        $table = $this->createTable('CREATE TABLE t (id INT DEFAULT 1 NOT NULL PRIMARY KEY)');

        $column = $table->columns[0];
        self::assertTrue($column->notNull);
        self::assertTrue($column->primaryKey);
        self::assertEquals(new Literal(1), $column->default);
    }

    public function testTableLevelPrimaryKey(): void
    {
        $table = $this->createTable('CREATE TABLE t (a INT, b INT, PRIMARY KEY (a, b))');

        self::assertEquals(new PrimaryKeyDefinition(['a', 'b']), $table->constraints[0]);
    }

    public function testTableLevelUniqueWithConstraintName(): void
    {
        $table = $this->createTable('CREATE TABLE t (email VARCHAR(255), CONSTRAINT uq_email UNIQUE (email))');

        self::assertEquals(new UniqueDefinition(['email'], 'uq_email'), $table->constraints[0]);
    }

    public function testTableLevelForeignKeyWithActions(): void
    {
        $table = $this->createTable(
            'CREATE TABLE orders (
                user_id INT,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )',
        );

        self::assertEquals(
            new ForeignKeyDefinition(['user_id'], 'users', ['id'], ReferentialAction::CASCADE),
            $table->constraints[0],
        );
    }

    public function testTableLevelCheck(): void
    {
        $table = $this->createTable('CREATE TABLE t (age INT, CHECK (age >= 0))');

        self::assertInstanceOf(CheckDefinition::class, $table->constraints[0]);
    }

    public function testIfNotExists(): void
    {
        self::assertTrue($this->createTable('CREATE TABLE IF NOT EXISTS t (a INT)')->ifNotExists);
        self::assertFalse($this->createTable('CREATE TABLE t (a INT)')->ifNotExists);
    }

    public function testFullExampleFromThePlan(): void
    {
        $table = $this->createTable(
            "CREATE TABLE users (
                id INT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                age INT DEFAULT 0 CHECK (age >= 0),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        );

        self::assertSame('users', $table->table);
        self::assertCount(4, $table->columns);
        self::assertSame('email', $table->columns[1]->name);
        self::assertTrue($table->columns[1]->notNull);
        self::assertTrue($table->columns[1]->unique);
    }

    public function testDropTable(): void
    {
        $statement = Parser::parseOne('DROP TABLE users');

        self::assertInstanceOf(DropTableStatement::class, $statement);
        self::assertSame('users', $statement->table);
        self::assertFalse($statement->ifExists);
    }

    public function testDropTableIfExists(): void
    {
        $statement = Parser::parseOne('DROP TABLE IF EXISTS users');

        self::assertInstanceOf(DropTableStatement::class, $statement);
        self::assertTrue($statement->ifExists);
    }

    public function testAlterTableAddColumn(): void
    {
        $statement = Parser::parseOne("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");

        self::assertInstanceOf(AlterTableStatement::class, $statement);
        self::assertSame('users', $statement->table);
        self::assertInstanceOf(AddColumn::class, $statement->action);
        self::assertSame('status', $statement->action->column->name);
    }

    public function testAlterTableAddColumnWithoutTheColumnKeyword(): void
    {
        $statement = Parser::parseOne('ALTER TABLE users ADD age INT');

        self::assertInstanceOf(AlterTableStatement::class, $statement);
        self::assertInstanceOf(AddColumn::class, $statement->action);
    }

    public function testAlterTableDropColumn(): void
    {
        $statement = Parser::parseOne('ALTER TABLE users DROP COLUMN status');

        self::assertInstanceOf(AlterTableStatement::class, $statement);
        self::assertEquals(new DropColumn('status'), $statement->action);
    }

    public function testCreateIndex(): void
    {
        $statement = Parser::parseOne('CREATE INDEX idx_users_age ON users (age)');

        self::assertInstanceOf(CreateIndexStatement::class, $statement);
        self::assertSame('idx_users_age', $statement->name);
        self::assertSame('users', $statement->table);
        self::assertSame(['age'], $statement->columns);
        self::assertFalse($statement->unique);
    }

    public function testCreateUniqueIndex(): void
    {
        $statement = Parser::parseOne('CREATE UNIQUE INDEX idx_users_email ON users (email)');

        self::assertInstanceOf(CreateIndexStatement::class, $statement);
        self::assertTrue($statement->unique);
    }

    public function testDropIndex(): void
    {
        $statement = Parser::parseOne('DROP INDEX idx_users_email ON users');

        self::assertInstanceOf(DropIndexStatement::class, $statement);
        self::assertSame('idx_users_email', $statement->name);
        self::assertSame('users', $statement->table);
    }
}

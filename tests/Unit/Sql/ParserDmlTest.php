<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Sql\Ast\Assignment;
use PhpMiniDatabase\Sql\Ast\DeleteStatement;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\InsertStatement;
use PhpMiniDatabase\Sql\Ast\UpdateStatement;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ParserDmlTest extends TestCase
{
    public function testInsertWithExplicitColumns(): void
    {
        $statement = Parser::parseOne("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");

        self::assertInstanceOf(InsertStatement::class, $statement);
        self::assertSame('users', $statement->table);
        self::assertSame(['id', 'email'], $statement->columns);
        self::assertEquals([[new Literal(1), new Literal('a@x.com')]], $statement->rows);
    }

    public function testInsertWithoutColumnsHasNullColumnList(): void
    {
        $statement = Parser::parseOne("INSERT INTO users VALUES (1, 'a@x.com')");

        self::assertInstanceOf(InsertStatement::class, $statement);
        self::assertNull($statement->columns);
    }

    public function testInsertWithMultipleRows(): void
    {
        $statement = Parser::parseOne("INSERT INTO users (id, email) VALUES (1, 'a@x.com'), (2, 'b@x.com')");

        self::assertInstanceOf(InsertStatement::class, $statement);
        self::assertCount(2, $statement->rows);
        self::assertEquals([new Literal(2), new Literal('b@x.com')], $statement->rows[1]);
    }

    public function testInsertWithAPlaceholder(): void
    {
        $statement = Parser::parseOne('INSERT INTO users (id) VALUES (?)');

        self::assertInstanceOf(InsertStatement::class, $statement);
        self::assertEquals(new Placeholder(0), $statement->rows[0][0]);
    }

    public function testUpdateWithMultipleAssignmentsAndWhere(): void
    {
        $statement = Parser::parseOne("UPDATE users SET age = age + 1, status = 'active' WHERE id = 1");

        self::assertInstanceOf(UpdateStatement::class, $statement);
        self::assertSame('users', $statement->table);
        self::assertEquals(
            new Assignment('age', new BinaryOp(new ColumnRef('age'), BinaryOperator::ADD, new Literal(1))),
            $statement->assignments[0],
        );
        self::assertEquals(new Assignment('status', new Literal('active')), $statement->assignments[1]);
        self::assertEquals(new BinaryOp(new ColumnRef('id'), BinaryOperator::EQUAL, new Literal(1)), $statement->where);
    }

    public function testUpdateWithoutWhere(): void
    {
        $statement = Parser::parseOne('UPDATE users SET active = TRUE');

        self::assertInstanceOf(UpdateStatement::class, $statement);
        self::assertNull($statement->where);
    }

    public function testDeleteWithWhere(): void
    {
        $statement = Parser::parseOne('DELETE FROM users WHERE age < 18');

        self::assertInstanceOf(DeleteStatement::class, $statement);
        self::assertSame('users', $statement->table);
        self::assertEquals(new BinaryOp(new ColumnRef('age'), BinaryOperator::LESS_THAN, new Literal(18)), $statement->where);
    }

    public function testDeleteWithoutWhere(): void
    {
        $statement = Parser::parseOne('DELETE FROM users');

        self::assertInstanceOf(DeleteStatement::class, $statement);
        self::assertNull($statement->where);
    }

    public function testMultipleStatementsSeparatedBySemicolons(): void
    {
        $statements = Parser::parse('DELETE FROM a; DELETE FROM b;');

        self::assertCount(2, $statements);
        self::assertInstanceOf(DeleteStatement::class, $statements[0]);
        self::assertInstanceOf(DeleteStatement::class, $statements[1]);
        self::assertSame('a', $statements[0]->table);
        self::assertSame('b', $statements[1]->table);
    }
}

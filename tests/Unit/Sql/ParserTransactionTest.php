<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Sql\Ast\BeginStatement;
use PhpMiniDatabase\Sql\Ast\CommitStatement;
use PhpMiniDatabase\Sql\Ast\ReleaseSavepointStatement;
use PhpMiniDatabase\Sql\Ast\RollbackStatement;
use PhpMiniDatabase\Sql\Ast\RollbackToSavepointStatement;
use PhpMiniDatabase\Sql\Ast\SavepointStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Transaction\IsolationLevel;
use PHPUnit\Framework\TestCase;

final class ParserTransactionTest extends TestCase
{
    public function testBareBegin(): void
    {
        $statement = Parser::parseOne('BEGIN');

        self::assertInstanceOf(BeginStatement::class, $statement);
        self::assertNull($statement->isolationLevel);
    }

    public function testBeginTransaction(): void
    {
        self::assertInstanceOf(BeginStatement::class, Parser::parseOne('BEGIN TRANSACTION'));
    }

    public function testStartTransaction(): void
    {
        self::assertInstanceOf(BeginStatement::class, Parser::parseOne('START TRANSACTION'));
    }

    public function testBeginWithEachIsolationLevel(): void
    {
        $cases = [
            'BEGIN ISOLATION LEVEL READ COMMITTED' => IsolationLevel::READ_COMMITTED,
            'BEGIN ISOLATION LEVEL REPEATABLE READ' => IsolationLevel::REPEATABLE_READ,
            'BEGIN ISOLATION LEVEL SERIALIZABLE' => IsolationLevel::SERIALIZABLE,
        ];

        foreach ($cases as $sql => $expected) {
            $statement = Parser::parseOne($sql);
            self::assertInstanceOf(BeginStatement::class, $statement);
            self::assertSame($expected, $statement->isolationLevel);
        }
    }

    public function testBareCommit(): void
    {
        self::assertInstanceOf(CommitStatement::class, Parser::parseOne('COMMIT'));
    }

    public function testCommitWorkAndCommitTransaction(): void
    {
        self::assertInstanceOf(CommitStatement::class, Parser::parseOne('COMMIT WORK'));
        self::assertInstanceOf(CommitStatement::class, Parser::parseOne('COMMIT TRANSACTION'));
    }

    public function testBareRollback(): void
    {
        self::assertInstanceOf(RollbackStatement::class, Parser::parseOne('ROLLBACK'));
    }

    public function testRollbackWork(): void
    {
        self::assertInstanceOf(RollbackStatement::class, Parser::parseOne('ROLLBACK WORK'));
    }

    public function testSavepoint(): void
    {
        $statement = Parser::parseOne('SAVEPOINT sp1');

        self::assertInstanceOf(SavepointStatement::class, $statement);
        self::assertSame('sp1', $statement->name);
    }

    public function testRollbackToSavepointWithTheSavepointKeyword(): void
    {
        $statement = Parser::parseOne('ROLLBACK TO SAVEPOINT sp1');

        self::assertInstanceOf(RollbackToSavepointStatement::class, $statement);
        self::assertSame('sp1', $statement->name);
    }

    public function testRollbackToSavepointWithoutTheSavepointKeyword(): void
    {
        $statement = Parser::parseOne('ROLLBACK TO sp1');

        self::assertInstanceOf(RollbackToSavepointStatement::class, $statement);
        self::assertSame('sp1', $statement->name);
    }

    public function testReleaseSavepoint(): void
    {
        $statement = Parser::parseOne('RELEASE SAVEPOINT sp1');

        self::assertInstanceOf(ReleaseSavepointStatement::class, $statement);
        self::assertSame('sp1', $statement->name);
    }

    public function testReleaseWithoutTheSavepointKeyword(): void
    {
        $statement = Parser::parseOne('RELEASE sp1');

        self::assertInstanceOf(ReleaseSavepointStatement::class, $statement);
        self::assertSame('sp1', $statement->name);
    }
}

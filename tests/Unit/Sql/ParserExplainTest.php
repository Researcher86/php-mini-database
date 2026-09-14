<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Exception\ParserException;
use PhpMiniDatabase\Sql\Ast\ExplainStatement;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ParserExplainTest extends TestCase
{
    public function testExplainWrapsTheSelectItNames(): void
    {
        $statement = Parser::parseOne('EXPLAIN SELECT * FROM users WHERE age > 18');

        self::assertInstanceOf(ExplainStatement::class, $statement);
        self::assertInstanceOf(BinaryOp::class, $statement->statement->where);
    }

    public function testExplainRefusesAnythingButSelect(): void
    {
        $this->expectException(ParserException::class);
        Parser::parseOne('EXPLAIN DELETE FROM users');
    }
}

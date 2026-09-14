<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Sql;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\ExpressionPrinter;
use PhpMiniDatabase\Sql\Parser;
use PHPUnit\Framework\TestCase;

final class ExpressionPrinterTest extends TestCase
{
    private ExpressionPrinter $printer;

    protected function setUp(): void
    {
        $this->printer = new ExpressionPrinter();
    }

    private function parseWhere(string $expression): Expression
    {
        $statement = Parser::parseOne("SELECT * FROM t WHERE {$expression}");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->where;
    }

    /**
     * The property that actually matters: print(parse(x)) reparses to a
     * tree equal to parse(x), for every expression shape a CHECK clause
     * could contain.
     */
    public function testPrintedTextReparsesToAnEquivalentTree(): void
    {
        $expressions = [
            'age >= 0',
            "age >= 0 AND status <> 'banned'",
            'age BETWEEN 18 AND 65',
            'age NOT BETWEEN 18 AND 65',
            'status IN (1, 2, 3)',
            'status NOT IN (1, 2, 3)',
            "email LIKE '%@x.com'",
            'deleted_at IS NULL',
            'deleted_at IS NOT NULL',
            'NOT active',
            '-age',
            'price * qty + 1',
            'UPPER(status)',
            "CONCAT(first, ' ', last)",
        ];

        foreach ($expressions as $sql) {
            $original = $this->parseWhere($sql);
            $reprinted = $this->parseWhere($this->printer->print($original));

            self::assertEquals($original, $reprinted, "Round trip failed for: {$sql}");
        }
    }

    public function testLiteralsArePrintedAsValidSql(): void
    {
        self::assertSame("'it''s'", $this->printer->print($this->parseWhere("'it''s'")));
        self::assertSame('NULL', $this->printer->print($this->parseWhere('NULL')));
        self::assertSame('TRUE', $this->printer->print($this->parseWhere('TRUE')));
        self::assertSame('FALSE', $this->printer->print($this->parseWhere('FALSE')));
    }

    public function testStarCannotBePrinted(): void
    {
        $this->expectException(ExecutionException::class);
        $this->printer->print(new Star());
    }

    public function testPlaceholderCannotBePrinted(): void
    {
        $this->expectException(ExecutionException::class);
        $this->printer->print(new Placeholder(0));
    }
}

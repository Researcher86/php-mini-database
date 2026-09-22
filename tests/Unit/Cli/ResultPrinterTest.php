<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\OutputFormat;
use PhpMiniDatabase\Cli\ResultPrinter;
use PhpMiniDatabase\Client\ResultSet;
use PhpMiniDatabase\Tests\Support\MemoryStream;
use PHPUnit\Framework\TestCase;

final class ResultPrinterTest extends TestCase
{
    use MemoryStream;

    private ResultPrinter $printer;

    protected function setUp(): void
    {
        $this->printer = new ResultPrinter();
    }

    /** @return resource */
    private function stream(): mixed
    {
        return $this->memoryStream();
    }

    private function contents(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function testTableFormatRendersABoxWithAlignedColumns(): void
    {
        $result = new ResultSet(['id', 'email'], [[1, 'alice@example.com'], [2, 'bob@example.com']]);
        $stream = $this->stream();

        $this->printer->print($result, OutputFormat::TABLE, $stream);

        $expected = <<<'TABLE'
        +----+-------------------+
        | id | email             |
        +----+-------------------+
        | 1  | alice@example.com |
        | 2  | bob@example.com   |
        +----+-------------------+

        TABLE;

        self::assertSame($expected, $this->contents($stream));
    }

    public function testTableFormatOfAnEmptyDdlResultPrintsNothing(): void
    {
        $result = new ResultSet([], []);
        $stream = $this->stream();

        $this->printer->print($result, OutputFormat::TABLE, $stream);

        self::assertSame('', $this->contents($stream));
    }

    public function testTableFormatRendersNullAndBooleanValues(): void
    {
        $result = new ResultSet(['active', 'note'], [[true, null], [false, 'ok']]);
        $stream = $this->stream();

        $this->printer->print($result, OutputFormat::TABLE, $stream);

        $output = $this->contents($stream);

        self::assertStringContainsString('| 1      | NULL |', $output);
        self::assertStringContainsString('| 0      | ok   |', $output);
    }

    public function testJsonFormatProducesAListOfAssociativeRows(): void
    {
        $result = new ResultSet(['id', 'name'], [[1, 'Ann']]);
        $stream = $this->stream();

        $this->printer->print($result, OutputFormat::JSON, $stream);

        self::assertSame([['id' => 1, 'name' => 'Ann']], json_decode($this->contents($stream), true));
    }

    public function testCsvFormatIncludesAHeaderRow(): void
    {
        $result = new ResultSet(['id', 'name'], [[1, 'Ann'], [2, 'Bob, Jr.']]);
        $stream = $this->stream();

        $this->printer->print($result, OutputFormat::CSV, $stream);

        self::assertSame("id,name\n1,Ann\n2,\"Bob, Jr.\"\n", $this->contents($stream));
    }

    public function testVerticalFormatShowsOneFieldPerLinePerRow(): void
    {
        $result = new ResultSet(['id', 'email'], [[1, 'alice@example.com']]);
        $stream = $this->stream();

        $this->printer->print($result, OutputFormat::VERTICAL, $stream);

        $expected = "*************************** 1. row ***************************\n"
            . "   id: 1\n"
            . "email: alice@example.com\n";

        self::assertSame($expected, $this->contents($stream));
    }

    public function testStatusLineForASelect(): void
    {
        $result = new ResultSet(['id'], [[1], [2]]);

        self::assertSame('2 rows in set (0.100 sec)', $this->printer->statusLine($result, 0.1));
    }

    public function testStatusLineForASingleRowIsSingular(): void
    {
        $result = new ResultSet(['id'], [[1]]);

        self::assertSame('1 row in set (0.000 sec)', $this->printer->statusLine($result, 0.0));
    }

    public function testStatusLineForDml(): void
    {
        $result = new ResultSet(['affected_rows'], [[3]]);

        self::assertSame('Query OK, 3 rows affected (0.020 sec)', $this->printer->statusLine($result, 0.02));
    }

    public function testStatusLineForDdl(): void
    {
        $result = new ResultSet([], []);

        self::assertSame('Query OK (0.005 sec)', $this->printer->statusLine($result, 0.005));
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Backup;

use DateTimeImmutable;
use PhpMiniDatabase\Backup\SqlLiteral;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class SqlLiteralTest extends TestCase
{
    public function testNull(): void
    {
        self::assertSame('NULL', SqlLiteral::format(null));
    }

    public function testBooleans(): void
    {
        self::assertSame('TRUE', SqlLiteral::format(true));
        self::assertSame('FALSE', SqlLiteral::format(false));
    }

    public function testNumbers(): void
    {
        self::assertSame('42', SqlLiteral::format(42));
        self::assertSame('3.5', SqlLiteral::format(3.5));
    }

    public function testAPlainString(): void
    {
        self::assertSame("'hello'", SqlLiteral::format('hello'));
    }

    public function testAStringWithAQuoteIsEscapedByDoublingIt(): void
    {
        self::assertSame("'O''Brien'", SqlLiteral::format("O'Brien"));
    }

    public function testADateTime(): void
    {
        $value = new DateTimeImmutable('2024-01-02 03:04:05');

        self::assertSame("'2024-01-02 03:04:05'", SqlLiteral::format($value));
    }

    public function testAnUnsupportedTypeThrows(): void
    {
        $this->expectException(RuntimeException::class);

        SqlLiteral::format(new stdClass());
    }
}

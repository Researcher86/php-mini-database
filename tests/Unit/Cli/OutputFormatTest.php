<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use InvalidArgumentException;
use PhpMiniDatabase\Cli\OutputFormat;
use PHPUnit\Framework\TestCase;

final class OutputFormatTest extends TestCase
{
    public function testDefaultsToTableWhenNoFormatFlagIsGiven(): void
    {
        self::assertSame(OutputFormat::TABLE, OutputFormat::fromFlags([]));
        self::assertSame(OutputFormat::TABLE, OutputFormat::fromFlags(['quiet' => true]));
    }

    public function testEachFlagSelectsItsFormat(): void
    {
        self::assertSame(OutputFormat::JSON, OutputFormat::fromFlags(['json' => true]));
        self::assertSame(OutputFormat::CSV, OutputFormat::fromFlags(['csv' => true]));
        self::assertSame(OutputFormat::VERTICAL, OutputFormat::fromFlags(['vertical' => true]));
    }

    public function testTwoFormatFlagsTogetherIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OutputFormat::fromFlags(['json' => true, 'csv' => true]);
    }
}

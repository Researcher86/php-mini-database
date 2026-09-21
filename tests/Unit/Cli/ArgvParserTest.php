<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\ArgvParser;
use PHPUnit\Framework\TestCase;

final class ArgvParserTest extends TestCase
{
    public function testCommandAndTrailingPositional(): void
    {
        $result = ArgvParser::parse(['query', 'SELECT 1']);

        self::assertSame('query', $result['command']);
        self::assertSame(['SELECT 1'], $result['args']);
        self::assertSame([], $result['options']);
        self::assertSame([], $result['flags']);
    }

    public function testValueTakingOptionsConsumeTheirNextToken(): void
    {
        $result = ArgvParser::parse(['query', '--host', '127.0.0.1', '--port', '5433', 'SELECT 1']);

        self::assertSame('query', $result['command']);
        self::assertSame(['SELECT 1'], $result['args']);
        self::assertSame(['host' => ['127.0.0.1'], 'port' => ['5433']], $result['options']);
    }

    public function testRepeatedOptionsAccumulate(): void
    {
        $result = ArgvParser::parse(['export', '--table', 'users', '--table', 'posts']);

        self::assertSame(['table' => ['users', 'posts']], $result['options']);
    }

    public function testBooleanFlagsTakeNoValueAndLeaveTheNextTokenAlone(): void
    {
        $result = ArgvParser::parse(['query', '--json', '--quiet', 'SELECT 1'], ['json', 'quiet']);

        self::assertSame(['json' => true, 'quiet' => true], $result['flags']);
        self::assertSame(['SELECT 1'], $result['args']);
        self::assertSame([], $result['options']);
    }

    public function testAFlagNotDeclaredBooleanStillConsumesAValue(): void
    {
        $result = ArgvParser::parse(['query', '--json', 'SELECT 1']);

        self::assertSame([], $result['flags']);
        self::assertSame(['json' => ['SELECT 1']], $result['options']);
        self::assertSame([], $result['args']);
    }

    public function testNoCommandGivenAtAll(): void
    {
        $result = ArgvParser::parse([]);

        self::assertNull($result['command']);
        self::assertSame([], $result['args']);
    }
}

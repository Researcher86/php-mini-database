<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Infrastructure;

use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Infrastructure\LogLevel;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testWithNoPathEverythingIsDiscarded(): void
    {
        $logger = new Logger();

        $logger->error('should go nowhere');

        $this->addToAssertionCount(1); // did not throw, nothing to read back
    }

    public function testAMessageIsAppendedToThePath(): void
    {
        $path = $this->path('server.log');
        $logger = new Logger($path);

        $logger->info('server started');

        self::assertStringContainsString('INFO', file_get_contents($path));
        self::assertStringContainsString('server started', file_get_contents($path));
    }

    public function testMultipleMessagesAppendRatherThanOverwrite(): void
    {
        $path = $this->path('server.log');
        $logger = new Logger($path);

        $logger->info('first');
        $logger->info('second');

        $contents = file_get_contents($path);
        self::assertStringContainsString('first', $contents);
        self::assertStringContainsString('second', $contents);
    }

    public function testAMessageBelowTheMinimumLevelIsDropped(): void
    {
        $path = $this->path('server.log');
        $logger = new Logger($path, LogLevel::WARNING);

        $logger->info('should not appear');
        $logger->warning('should appear');

        $contents = file_get_contents($path);
        self::assertStringNotContainsString('should not appear', $contents);
        self::assertStringContainsString('should appear', $contents);
    }

    public function testEveryLevelIsLabelledCorrectly(): void
    {
        $path = $this->path('server.log');
        $logger = new Logger($path, LogLevel::DEBUG);

        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');

        $contents = file_get_contents($path);
        self::assertStringContainsString('DEBUG', $contents);
        self::assertStringContainsString('INFO', $contents);
        self::assertStringContainsString('WARNING', $contents);
        self::assertStringContainsString('ERROR', $contents);
    }
}

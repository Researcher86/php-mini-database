<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Cli;

use PhpMiniDatabase\Cli\AdminCommand;
use PhpMiniDatabase\Cli\AdminCommandKind;
use PHPUnit\Framework\TestCase;

final class AdminCommandTest extends TestCase
{
    public function testRecognizesShowStatusCaseInsensitively(): void
    {
        $command = AdminCommand::parse('show status');

        self::assertNotNull($command);
        self::assertSame(AdminCommandKind::SHOW_STATUS, $command->kind);
    }

    public function testRecognizesShowConnectionsWithSurroundingWhitespace(): void
    {
        $command = AdminCommand::parse("  SHOW CONNECTIONS  \n");

        self::assertNotNull($command);
        self::assertSame(AdminCommandKind::SHOW_CONNECTIONS, $command->kind);
    }

    public function testRecognizesKillWithItsConnectionId(): void
    {
        $command = AdminCommand::parse('kill 42');

        self::assertNotNull($command);
        self::assertSame(AdminCommandKind::KILL, $command->kind);
        self::assertSame(42, $command->connectionId);
    }

    public function testKillIsCaseInsensitiveAndTolerantOfExtraSpace(): void
    {
        $command = AdminCommand::parse('KILL    7');

        self::assertNotNull($command);
        self::assertSame(7, $command->connectionId);
    }

    public function testOrdinarySqlIsNotRecognized(): void
    {
        self::assertNull(AdminCommand::parse('SELECT * FROM users'));
        self::assertNull(AdminCommand::parse('SHOW TABLES'));
        self::assertNull(AdminCommand::parse('KILL'));
        self::assertNull(AdminCommand::parse('KILL abc'));
        self::assertNull(AdminCommand::parse(''));
    }
}

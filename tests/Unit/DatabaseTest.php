<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test that exists so the suite is never empty. It is replaced by
 * behaviour tests as soon as Phase 1 gives Database something to do.
 */
final class DatabaseTest extends TestCase
{
    public function testItCanBeInstantiated(): void
    {
        self::assertInstanceOf(Database::class, new Database());
    }
}

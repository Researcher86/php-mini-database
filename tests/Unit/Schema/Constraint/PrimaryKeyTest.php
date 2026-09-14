<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Constraint\PrimaryKey;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyTest extends TestCase
{
    public function testCarriesItsColumnsAndIsAlwaysNamedPrimary(): void
    {
        $pk = new PrimaryKey(['id']);

        self::assertSame('PRIMARY', $pk->name());
        self::assertSame(['id'], $pk->columns());
    }

    public function testSupportsACompositeKey(): void
    {
        $pk = new PrimaryKey(['tenant_id', 'id']);

        self::assertSame(['tenant_id', 'id'], $pk->columns());
    }

    public function testMustNameAtLeastOneColumn(): void
    {
        $this->expectException(SchemaException::class);
        new PrimaryKey([]);
    }
}

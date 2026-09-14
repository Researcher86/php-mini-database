<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\IndexDefinition;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionTest extends TestCase
{
    public function testCarriesItsNameColumnsAndUniqueFlag(): void
    {
        $index = new IndexDefinition('idx_users_email', ['email'], unique: true);

        self::assertSame('idx_users_email', $index->name);
        self::assertSame(['email'], $index->columns());
        self::assertTrue($index->unique);
    }

    public function testDefaultsToNotUnique(): void
    {
        self::assertFalse((new IndexDefinition('idx_users_age', ['age']))->unique);
    }

    public function testMustNameAtLeastOneColumn(): void
    {
        $this->expectException(SchemaException::class);
        new IndexDefinition('idx_empty', []);
    }
}

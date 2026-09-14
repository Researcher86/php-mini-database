<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Constraint\ForeignKey;
use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
use PHPUnit\Framework\TestCase;

final class ForeignKeyTest extends TestCase
{
    public function testCarriesBothSidesOfTheReference(): void
    {
        $fk = ForeignKey::on('orders', ['user_id'], 'users', ['id']);

        self::assertSame('fk_orders_user_id', $fk->name());
        self::assertSame(['user_id'], $fk->columns());
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns());
    }

    public function testDefaultsToNoAction(): void
    {
        $fk = ForeignKey::on('orders', ['user_id'], 'users', ['id']);

        self::assertSame(ReferentialAction::NO_ACTION, $fk->onDelete);
        self::assertSame(ReferentialAction::NO_ACTION, $fk->onUpdate);
    }

    public function testCarriesExplicitActions(): void
    {
        $fk = ForeignKey::on(
            'orders',
            ['user_id'],
            'users',
            ['id'],
            onDelete: ReferentialAction::CASCADE,
            onUpdate: ReferentialAction::SET_NULL,
        );

        self::assertSame(ReferentialAction::CASCADE, $fk->onDelete);
        self::assertSame(ReferentialAction::SET_NULL, $fk->onUpdate);
    }

    public function testMustNameAtLeastOneColumn(): void
    {
        $this->expectException(SchemaException::class);
        ForeignKey::on('orders', [], 'users', []);
    }

    /**
     * A composite foreign key matches column by column, in order, so a
     * mismatched side count has no meaning to fall back to.
     */
    public function testColumnCountsOnBothSidesMustMatch(): void
    {
        $this->expectException(SchemaException::class);
        ForeignKey::on('orders', ['user_id', 'tenant_id'], 'users', ['id']);
    }
}

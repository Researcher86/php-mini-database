<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Constraint\UniqueConstraint;
use PHPUnit\Framework\TestCase;

final class UniqueConstraintTest extends TestCase
{
    public function testCarriesAnExplicitName(): void
    {
        $constraint = new UniqueConstraint('uq_users_email', ['email']);

        self::assertSame('uq_users_email', $constraint->name());
        self::assertSame(['email'], $constraint->columns());
    }

    public function testOnBuildsTheConventionalName(): void
    {
        $constraint = UniqueConstraint::on('users', ['email']);

        self::assertSame('uq_users_email', $constraint->name());
    }

    public function testOnJoinsACompositeColumnListIntoTheName(): void
    {
        $constraint = UniqueConstraint::on('memberships', ['tenant_id', 'user_id']);

        self::assertSame('uq_memberships_tenant_id_user_id', $constraint->name());
    }

    public function testMustNameAtLeastOneColumn(): void
    {
        $this->expectException(SchemaException::class);
        new UniqueConstraint('uq_empty', []);
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Schema\Constraint;

use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Schema\Constraint\CheckConstraint;
use PHPUnit\Framework\TestCase;

final class CheckConstraintTest extends TestCase
{
    public function testCarriesItsExpressionAsWrittenText(): void
    {
        $check = CheckConstraint::on('users', 'age >= 0', ['age']);

        self::assertSame('ck_users_age', $check->name());
        self::assertSame('age >= 0', $check->expression);
        self::assertSame(['age'], $check->columns());
    }

    public function testColumnsDefaultsToEmptyWhenNotKnown(): void
    {
        $check = new CheckConstraint('ck_custom', 'age >= 0');

        self::assertSame([], $check->columns());
    }

    public function testAnEmptyExpressionIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        new CheckConstraint('ck_empty', '   ');
    }
}

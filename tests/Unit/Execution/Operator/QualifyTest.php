<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Operator\Qualify;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class QualifyTest extends TestCase
{
    public function testEveryColumnIsPrefixedWithTheReference(): void
    {
        $source = new ListOperator([new Row(['id' => 1, 'name' => 'alice'])]);

        $qualified = iterator_to_array(new Qualify($source, 'u'), false);

        self::assertSame(['u.id' => 1, 'u.name' => 'alice'], $qualified[0]->toArray());
    }

    public function testAnEmptySourceYieldsNothing(): void
    {
        self::assertSame([], iterator_to_array(new Qualify(new ListOperator([]), 'u'), false));
    }
}

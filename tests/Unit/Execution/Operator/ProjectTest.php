<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Operator;

use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Operator\Project;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Tests\Support\ListOperator;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    /** @return list<SelectItem> */
    private function selectItems(string $sql): array
    {
        $statement = Parser::parseOne("SELECT {$sql} FROM t");
        self::assertInstanceOf(SelectStatement::class, $statement);

        return $statement->columns;
    }

    private function project(string $sql, Row $row): Row
    {
        $items = $this->selectItems($sql);
        $labels = array_map(static fn (SelectItem $item, int $i): string => Project::label($item, $i), $items, array_keys($items));

        $project = new Project(new ListOperator([$row]), $items, $labels, new Evaluator(), 't');

        return iterator_to_array($project, false)[0];
    }

    public function testAPlainColumnKeepsItsOwnName(): void
    {
        self::assertSame(['id' => 1], $this->project('id', new Row(['id' => 1, 'name' => 'a']))->toArray());
    }

    public function testAnExplicitAliasIsUsed(): void
    {
        self::assertSame(
            ['total' => 30],
            $this->project('age AS total', new Row(['age' => 30]))->toArray(),
        );
    }

    public function testAFunctionCallIsNamedAfterTheFunction(): void
    {
        self::assertSame(
            ['UPPER' => 'ALICE'],
            $this->project("UPPER(name)", new Row(['name' => 'alice']))->toArray(),
        );
    }

    public function testAnUnnamedExpressionFallsBackToItsPosition(): void
    {
        self::assertSame(
            ['column1' => 3],
            $this->project('1 + 2', new Row([]))->toArray(),
        );
    }

    public function testMultipleColumnsProjectInOrder(): void
    {
        $row = $this->project('id, name', new Row(['id' => 1, 'name' => 'alice', 'age' => 30]));

        self::assertSame(['id' => 1, 'name' => 'alice'], $row->toArray());
    }
}

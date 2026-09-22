<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Execution\Expression;

use DateTimeImmutable;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Execution\Expression\Evaluator;
use PhpMiniDatabase\Execution\Expression\RowContext;
use PhpMiniDatabase\Schema\Row;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Parser;
use PhpMiniDatabase\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator(new FakeClock('2024-06-15 12:00:00'));
    }

    private function expression(string $sql): Expression
    {
        $statement = Parser::parseOne("SELECT * FROM t WHERE {$sql}");
        self::assertInstanceOf(SelectStatement::class, $statement);
        self::assertNotNull($statement->where);

        return $statement->where;
    }

    /** @param list<mixed> $parameters */
    private function eval(string $sql, ?Row $row = null, array $parameters = []): mixed
    {
        return $this->evaluator->evaluate($this->expression($sql), new RowContext($row, 't', null, $parameters));
    }

    public function testArithmetic(): void
    {
        self::assertSame(7, $this->eval('3 + 4'));
        self::assertSame(-1, $this->eval('3 - 4'));
        self::assertSame(12, $this->eval('3 * 4'));
        self::assertSame(2.5, $this->eval('5 / 2'));
        self::assertSame(1, $this->eval('7 % 3'));
        self::assertSame(-5, $this->eval('-5'));
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('1 / 0');
    }

    public function testComparisons(): void
    {
        self::assertTrue($this->eval('3 < 4'));
        self::assertFalse($this->eval('4 < 3'));
        self::assertTrue($this->eval('4 = 4'));
        self::assertTrue($this->eval("'a' <> 'b'"));
    }

    public function testColumnReferenceResolvesFromTheRow(): void
    {
        self::assertSame(30, $this->eval('age', new Row(['age' => 30])));
    }

    public function testQualifiedColumnMatchesTheTableName(): void
    {
        self::assertSame(30, $this->eval('t.age', new Row(['age' => 30])));
    }

    public function testUnknownQualifierThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('other.age', new Row(['age' => 30]));
    }

    public function testUnknownColumnThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('missing', new Row(['age' => 30]));
    }

    public function testColumnWithNoRowInScopeThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('age');
    }

    public function testPlaceholderResolvesFromBoundParameters(): void
    {
        self::assertSame(42, $this->eval('?', parameters: [42]));
    }

    public function testMissingParameterThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('?');
    }

    // --- Three-valued logic ---

    public function testNullPropagatesThroughArithmetic(): void
    {
        self::assertNull($this->eval('age + 1', new Row(['age' => null])));
    }

    public function testNullPropagatesThroughComparison(): void
    {
        self::assertNull($this->eval('age > 1', new Row(['age' => null])));
    }

    public function testAndShortCircuitsOnAFalseLeftSide(): void
    {
        // FALSE AND NULL is FALSE, not NULL - the left side alone decides.
        self::assertFalse($this->eval('FALSE AND (1/0 = 0)'));
    }

    public function testOrShortCircuitsOnATrueLeftSide(): void
    {
        self::assertTrue($this->eval('TRUE OR (1/0 = 0)'));
    }

    public function testAndWithANullOperandIsNullUnlessTheOtherIsFalse(): void
    {
        self::assertNull($this->eval('age > 1 AND TRUE', new Row(['age' => null])));
        self::assertFalse($this->eval('age > 1 AND FALSE', new Row(['age' => null])));
    }

    public function testOrWithANullOperandIsNullUnlessTheOtherIsTrue(): void
    {
        self::assertNull($this->eval('age > 1 OR FALSE', new Row(['age' => null])));
        self::assertTrue($this->eval('age > 1 OR TRUE', new Row(['age' => null])));
    }

    public function testNotOfNullIsNull(): void
    {
        self::assertNull($this->eval('NOT age', new Row(['age' => null])));
    }

    public function testIsNullNeverPropagatesAndAlwaysAnswers(): void
    {
        self::assertTrue($this->eval('age IS NULL', new Row(['age' => null])));
        self::assertFalse($this->eval('age IS NOT NULL', new Row(['age' => null])));
        self::assertFalse($this->eval('age IS NULL', new Row(['age' => 1])));
    }

    public function testBetweenPropagatesNull(): void
    {
        self::assertNull($this->eval('age BETWEEN 1 AND 10', new Row(['age' => null])));
        self::assertTrue($this->eval('age BETWEEN 1 AND 10', new Row(['age' => 5])));
    }

    public function testLikePropagatesNull(): void
    {
        self::assertNull($this->eval('name LIKE ' . "'%a%'", new Row(['name' => null])));
    }

    public function testLikeWildcards(): void
    {
        self::assertTrue($this->eval("'hello' LIKE 'h_llo'"));
        self::assertTrue($this->eval("'hello world' LIKE 'hello%'"));
        self::assertFalse($this->eval("'hello' LIKE 'world%'"));
    }

    public function testInListWithoutNullIsAOrdinaryTest(): void
    {
        self::assertTrue($this->eval('2 IN (1, 2, 3)'));
        self::assertFalse($this->eval('4 IN (1, 2, 3)'));
    }

    public function testInListSubjectNullIsAlwaysNull(): void
    {
        self::assertNull($this->eval('age IN (1, 2, 3)', new Row(['age' => null])));
    }

    /**
     * "2 IN (1, NULL)" is neither true nor false: 2 does not match 1, but it
     * might have matched whatever NULL actually is.
     */
    public function testInListWithANonMatchingNullIsUnknown(): void
    {
        self::assertNull($this->eval('2 IN (1, NULL)'));
    }

    public function testInListWithAMatchIgnoresOtherNulls(): void
    {
        self::assertTrue($this->eval('1 IN (1, NULL)'));
    }

    // --- Functions ---

    public function testStringFunctions(): void
    {
        self::assertSame('ABC', $this->eval("UPPER('abc')"));
        self::assertSame('abc', $this->eval("LOWER('ABC')"));
        self::assertSame(3, $this->eval("LENGTH('abc')"));
        self::assertSame('a b', $this->eval("CONCAT('a', ' ', 'b')"));
    }

    public function testAbs(): void
    {
        self::assertSame(5, $this->eval('ABS(-5)'));
    }

    public function testCoalesceReturnsFirstNonNull(): void
    {
        self::assertSame(2, $this->eval('COALESCE(NULL, 2, 3)'));
        self::assertNull($this->eval('COALESCE(NULL, NULL)'));
    }

    public function testNullPropagatingFunctionsReturnNullOnANullArgument(): void
    {
        self::assertNull($this->eval('UPPER(name)', new Row(['name' => null])));
    }

    public function testCurrentTimestampUsesTheInjectedClock(): void
    {
        $result = $this->eval('CURRENT_TIMESTAMP');

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-06-15 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testCurrentDateTruncatesToMidnight(): void
    {
        $result = $this->eval('CURRENT_DATE');

        self::assertSame('2024-06-15 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testUnknownFunctionThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('NO_SUCH_FUNCTION(1)');
    }

    public function testWrongArgumentCountThrows(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval("UPPER('a', 'b')");
    }

    // --- Unsupported (deferred) ---

    public function testStarCannotBeEvaluated(): void
    {
        $this->expectException(ExecutionException::class);
        $this->evaluator->evaluate(new Star(), new RowContext());
    }

    public function testSubqueriesAreNotYetSupported(): void
    {
        $this->expectException(ExecutionException::class);
        $this->eval('age IN (SELECT id FROM other)');
    }
}

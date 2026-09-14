<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Execution\Expression;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Between;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\InList;
use PhpMiniDatabase\Sql\Ast\Expression\InSubquery;
use PhpMiniDatabase\Sql\Ast\Expression\IsNull;
use PhpMiniDatabase\Sql\Ast\Expression\Like;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\Expression\ScalarSubquery;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOperator;
use PhpMiniDatabase\Support\Clock;
use PhpMiniDatabase\Support\SystemClock;

/**
 * Walks an `Expression` tree and produces its value, given a row (or none)
 * to resolve columns against.
 *
 * SQL's three-valued logic is implemented, not approximated: a `NULL`
 * operand makes a comparison, an arithmetic operation or a logical
 * combination evaluate to PHP `null` — "unknown" — rather than to `false`,
 * following the standard truth tables (`NULL AND FALSE` is `FALSE`, not
 * `NULL`, because the answer is determined either way once one side is
 * `FALSE`). Only `IS [NOT] NULL` is exempt: it exists specifically to ask
 * the question three-valued logic can't otherwise answer, so it always
 * returns a definite `bool`.
 *
 * Subqueries (`ScalarSubquery`, `InSubquery`) are parsed but not yet
 * evaluated — running one needs the executor to run a nested `SELECT`,
 * which is deferred along with `JOIN` (see DECISIONS.md).
 */
final readonly class Evaluator
{
    private const NULL_PROPAGATING_FUNCTIONS = ['UPPER', 'LOWER', 'LENGTH', 'ABS', 'CONCAT'];

    public function __construct(
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function evaluate(Expression $expression, EvaluationContext $context): mixed
    {
        return match (true) {
            $expression instanceof Literal => $expression->value,
            $expression instanceof ColumnRef => $context->column($expression->qualifier, $expression->column),
            $expression instanceof Placeholder => $context->parameter($expression->index),
            $expression instanceof BinaryOp => $this->binaryOp($expression, $context),
            $expression instanceof UnaryOp => $this->unaryOp($expression, $context),
            $expression instanceof Between => $this->between($expression, $context),
            $expression instanceof IsNull => $this->isNull($expression, $context),
            $expression instanceof Like => $this->like($expression, $context),
            $expression instanceof InList => $this->inList($expression, $context),
            $expression instanceof FunctionCall => $this->functionCall($expression, $context),
            $expression instanceof Star => throw new ExecutionException('"*" cannot be used as a value.'),
            $expression instanceof InSubquery, $expression instanceof ScalarSubquery => throw new ExecutionException(
                'Subqueries are not supported yet.',
            ),
            default => throw new ExecutionException(sprintf('Cannot evaluate expression of type %s.', $expression::class)),
        };
    }

    /**
     * Whether an evaluated value counts as satisfying a `WHERE`/`HAVING`
     * condition: only a definite `true` does. `NULL` (unknown) and `false`
     * both exclude the row, which is what three-valued logic means in a
     * filter position.
     */
    public function isTrue(mixed $value): bool
    {
        return $this->toBoolean($value) === true;
    }

    private function toBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value,
            is_int($value) || is_float($value) => $value != 0,
            default => throw new ExecutionException(sprintf('Cannot use a %s as a boolean condition.', get_debug_type($value))),
        };
    }

    private function binaryOp(BinaryOp $expression, EvaluationContext $context): mixed
    {
        if (in_array($expression->operator, [BinaryOperator::AND, BinaryOperator::OR], true)) {
            return $this->logical($expression, $context);
        }

        $left = $this->evaluate($expression->left, $context);
        $right = $this->evaluate($expression->right, $context);

        if ($left === null || $right === null) {
            return null;
        }

        return match ($expression->operator) {
            BinaryOperator::ADD => $this->numeric($left) + $this->numeric($right),
            BinaryOperator::SUBTRACT => $this->numeric($left) - $this->numeric($right),
            BinaryOperator::MULTIPLY => $this->numeric($left) * $this->numeric($right),
            BinaryOperator::DIVIDE => $this->numeric($right) == 0
                ? throw new ExecutionException('Division by zero.')
                : $this->numeric($left) / $this->numeric($right),
            BinaryOperator::MODULO => $this->numeric($right) == 0
                ? throw new ExecutionException('Division by zero.')
                : (int) $this->numeric($left) % (int) $this->numeric($right),
            BinaryOperator::EQUAL => ($left <=> $right) === 0,
            BinaryOperator::NOT_EQUAL => ($left <=> $right) !== 0,
            BinaryOperator::LESS_THAN => ($left <=> $right) < 0,
            BinaryOperator::LESS_THAN_OR_EQUAL => ($left <=> $right) <= 0,
            BinaryOperator::GREATER_THAN => ($left <=> $right) > 0,
            BinaryOperator::GREATER_THAN_OR_EQUAL => ($left <=> $right) >= 0,
        };
    }

    /**
     * AND/OR short-circuit on the operand that already decides the answer,
     * which is also what makes their three-valued truth tables come out
     * right: `FALSE AND NULL` is `FALSE`, not `NULL`, because the left side
     * alone already settles it.
     */
    private function logical(BinaryOp $expression, EvaluationContext $context): ?bool
    {
        $left = $this->toBoolean($this->evaluate($expression->left, $context));

        if ($expression->operator === BinaryOperator::AND && $left === false) {
            return false;
        }
        if ($expression->operator === BinaryOperator::OR && $left === true) {
            return true;
        }

        $right = $this->toBoolean($this->evaluate($expression->right, $context));

        if ($expression->operator === BinaryOperator::AND) {
            if ($right === false) {
                return false;
            }

            return $left === true && $right === true ? true : null;
        }

        if ($right === true) {
            return true;
        }

        return $left === false && $right === false ? false : null;
    }

    private function unaryOp(UnaryOp $expression, EvaluationContext $context): mixed
    {
        $value = $this->evaluate($expression->operand, $context);

        if ($expression->operator === UnaryOperator::NOT) {
            $bool = $this->toBoolean($value);

            return $bool === null ? null : !$bool;
        }

        return $value === null ? null : -$this->numeric($value);
    }

    private function isNull(IsNull $expression, EvaluationContext $context): bool
    {
        $isNull = $this->evaluate($expression->subject, $context) === null;

        return $expression->negated ? !$isNull : $isNull;
    }

    private function between(Between $expression, EvaluationContext $context): ?bool
    {
        $subject = $this->evaluate($expression->subject, $context);
        $low = $this->evaluate($expression->low, $context);
        $high = $this->evaluate($expression->high, $context);

        if ($subject === null || $low === null || $high === null) {
            return null;
        }

        $inRange = ($subject <=> $low) >= 0 && ($subject <=> $high) <= 0;

        return $expression->negated ? !$inRange : $inRange;
    }

    private function like(Like $expression, EvaluationContext $context): ?bool
    {
        $subject = $this->evaluate($expression->subject, $context);
        $pattern = $this->evaluate($expression->pattern, $context);

        if ($subject === null || $pattern === null) {
            return null;
        }

        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string) $pattern, '/')) . '$/su';
        $matches = preg_match($regex, (string) $subject) === 1;

        return $expression->negated ? !$matches : $matches;
    }

    private function inList(InList $expression, EvaluationContext $context): ?bool
    {
        $subject = $this->evaluate($expression->subject, $context);

        if ($subject === null) {
            return null;
        }

        $sawNull = false;

        foreach ($expression->values as $candidate) {
            $value = $this->evaluate($candidate, $context);

            if ($value === null) {
                $sawNull = true;
                continue;
            }

            if (($subject <=> $value) === 0) {
                return !$expression->negated;
            }
        }

        // No match found: NULL propagates only if it could have masked one
        // (per standard SQL, "x IN (1, NULL)" is neither TRUE nor FALSE
        // unless x actually equals 1).
        return $sawNull ? null : $expression->negated;
    }

    private function functionCall(FunctionCall $expression, EvaluationContext $context): mixed
    {
        $name = strtoupper($expression->name);

        if ($name === 'CURRENT_TIMESTAMP' || $name === 'CURRENT_DATE') {
            $this->requireArgumentCount($name, $expression->arguments, 0);

            return $name === 'CURRENT_DATE' ? $this->clock->now()->setTime(0, 0) : $this->clock->now();
        }

        if ($name === 'COALESCE') {
            foreach ($expression->arguments as $argument) {
                $value = $this->evaluate($argument, $context);

                if ($value !== null) {
                    return $value;
                }
            }

            return null;
        }

        $arguments = array_map(fn (Expression $argument): mixed => $this->evaluate($argument, $context), $expression->arguments);

        if (in_array($name, self::NULL_PROPAGATING_FUNCTIONS, true) && in_array(null, $arguments, true)) {
            return null;
        }

        return match ($name) {
            'UPPER' => strtoupper((string) $this->oneArgument($name, $arguments)),
            'LOWER' => strtolower((string) $this->oneArgument($name, $arguments)),
            'LENGTH' => strlen((string) $this->oneArgument($name, $arguments)),
            'ABS' => abs($this->numeric($this->oneArgument($name, $arguments))),
            'CONCAT' => implode('', array_map(strval(...), $arguments)),
            default => throw new ExecutionException(sprintf('Unknown function "%s".', $expression->name)),
        };
    }

    /** @param list<mixed> $arguments */
    private function oneArgument(string $name, array $arguments): mixed
    {
        $this->requireArgumentCount($name, $arguments, 1);

        return $arguments[0];
    }

    /** @param list<mixed> $arguments */
    private function requireArgumentCount(string $name, array $arguments, int $expected): void
    {
        if (count($arguments) !== $expected) {
            throw new ExecutionException(sprintf('%s() expects %d argument(s), got %d.', $name, $expected, count($arguments)));
        }
    }

    private function numeric(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        throw new ExecutionException(sprintf('Expected a number, got %s.', get_debug_type($value)));
    }
}

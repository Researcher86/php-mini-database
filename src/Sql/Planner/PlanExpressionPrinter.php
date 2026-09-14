<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Planner;

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

/**
 * Renders an `Expression` as short, readable text for `EXPLAIN` — not the
 * same job `Sql\ExpressionPrinter` does. That one has to produce text
 * `Parser` can reparse into an equivalent tree, for `CHECK (...)`'s sake,
 * and refuses a `Placeholder`/`Star`/subquery accordingly, since none of
 * those are legal inside a `CHECK` expression. An `EXPLAIN` plan is read by
 * a person, not re-parsed, and a `?` or a `*` is exactly what a person
 * planning a prepared statement's query needs to see — so this class
 * prints both, and never refuses an expression `Sql\ExpressionPrinter`
 * would.
 */
final class PlanExpressionPrinter
{
    public function print(Expression $expression): string
    {
        return match (true) {
            $expression instanceof Literal => $this->literal($expression->value),
            $expression instanceof ColumnRef => $expression->qualifier !== null
                ? sprintf('%s.%s', $expression->qualifier, $expression->column)
                : $expression->column,
            $expression instanceof Placeholder => '?',
            $expression instanceof Star => $expression->qualifier !== null ? sprintf('%s.*', $expression->qualifier) : '*',
            $expression instanceof BinaryOp => sprintf(
                '%s %s %s',
                $this->print($expression->left),
                $this->binaryOperator($expression->operator),
                $this->print($expression->right),
            ),
            $expression instanceof UnaryOp => sprintf(
                '%s%s',
                $expression->operator === UnaryOperator::NOT ? 'NOT ' : '-',
                $this->print($expression->operand),
            ),
            $expression instanceof Between => sprintf(
                '%s %sBETWEEN %s AND %s',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
                $this->print($expression->low),
                $this->print($expression->high),
            ),
            $expression instanceof IsNull => sprintf(
                '%s IS %sNULL',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
            ),
            $expression instanceof Like => sprintf(
                '%s %sLIKE %s',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
                $this->print($expression->pattern),
            ),
            $expression instanceof InList => sprintf(
                '%s %sIN (%s)',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
                implode(', ', array_map($this->print(...), $expression->values)),
            ),
            $expression instanceof FunctionCall => sprintf(
                '%s(%s%s)',
                $expression->name,
                $expression->distinct ? 'DISTINCT ' : '',
                implode(', ', array_map($this->print(...), $expression->arguments)),
            ),
            $expression instanceof InSubquery, $expression instanceof ScalarSubquery => '(subquery)',
            default => throw new ExecutionException(sprintf('Cannot print expression of type %s.', $expression::class)),
        };
    }

    private function literal(int|float|string|bool|null $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => (string) $value,
        };
    }

    private function binaryOperator(BinaryOperator $operator): string
    {
        return match ($operator) {
            BinaryOperator::ADD => '+',
            BinaryOperator::SUBTRACT => '-',
            BinaryOperator::MULTIPLY => '*',
            BinaryOperator::DIVIDE => '/',
            BinaryOperator::MODULO => '%',
            BinaryOperator::EQUAL => '=',
            BinaryOperator::NOT_EQUAL => '<>',
            BinaryOperator::LESS_THAN => '<',
            BinaryOperator::LESS_THAN_OR_EQUAL => '<=',
            BinaryOperator::GREATER_THAN => '>',
            BinaryOperator::GREATER_THAN_OR_EQUAL => '>=',
            BinaryOperator::AND => 'AND',
            BinaryOperator::OR => 'OR',
        };
    }
}

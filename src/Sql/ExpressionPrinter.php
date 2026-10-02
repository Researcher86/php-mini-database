<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql;

use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Between;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
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
 * `Parser`'s dual: turns an `Expression` back into text, in one of two
 * modes that differ only in who reads the result.
 *
 * The default mode produces SQL that `Parser::parseExpression()` reparses
 * to an equivalent tree. It is what `CHECK (...)` needs:
 * `Schema\Constraint\CheckConstraint` keeps its expression as a string (a
 * decision made before a parser existed — see DECISIONS.md), so whatever
 * builds one from a parsed `CREATE TABLE` needs a text form to hand it.
 * Every operator's operands are parenthesized unconditionally, whether or
 * not precedence would require it. Precedence-minimal printing would need
 * this class to track the exact same precedence table as `Parser` and keep
 * the two in step by hand; over-parenthesizing needs nothing but the tree
 * itself; and correctness — the printed text always reparses to a tree
 * `Parser` agrees is equivalent — is the only property that matters there.
 * `Star`, `Placeholder` and the two subquery nodes have no legal place in a
 * `CHECK` expression and are refused rather than printed.
 *
 * `forExplain()` is the other mode: short, readable text for an `EXPLAIN`
 * line. A plan is read by a person, not re-parsed, so the defensive
 * parentheses go, and a `?` or a `*` — exactly what a person planning a
 * prepared statement's query needs to see — is printed instead of refused.
 */
final readonly class ExpressionPrinter
{
    public function __construct(
        private bool $forExplain = false,
    ) {
    }

    public static function forExplain(): self
    {
        return new self(forExplain: true);
    }

    public function print(Expression $expression): string
    {
        $not = static fn (bool $negated): string => $negated ? 'NOT ' : '';

        return match (true) {
            $expression instanceof Literal => $this->literal($expression->value),
            $expression instanceof ColumnRef => $expression->qualifier !== null
                ? sprintf('%s.%s', $expression->qualifier, $expression->column)
                : $expression->column,
            $expression instanceof BinaryOp => $this->group(sprintf(
                '%s %s %s',
                $this->print($expression->left),
                $expression->operator->symbol(),
                $this->print($expression->right),
            )),
            $expression instanceof UnaryOp => $this->forExplain
                ? ($expression->operator === UnaryOperator::NOT ? 'NOT ' : '-') . $this->print($expression->operand)
                : sprintf('%s (%s)', $expression->operator === UnaryOperator::NOT ? 'NOT' : '-', $this->print($expression->operand)),
            $expression instanceof Between => $this->group(sprintf(
                '%s %sBETWEEN %s AND %s',
                $this->print($expression->subject),
                $not($expression->negated),
                $this->print($expression->low),
                $this->print($expression->high),
            )),
            $expression instanceof IsNull => $this->group(sprintf(
                '%s IS %sNULL',
                $this->print($expression->subject),
                $not($expression->negated),
            )),
            $expression instanceof Like => $this->group(sprintf(
                '%s %sLIKE %s',
                $this->print($expression->subject),
                $not($expression->negated),
                $this->print($expression->pattern),
            )),
            $expression instanceof InList => $this->group(sprintf(
                '%s %sIN (%s)',
                $this->print($expression->subject),
                $not($expression->negated),
                implode(', ', array_map($this->print(...), $expression->values)),
            )),
            $expression instanceof FunctionCall => sprintf(
                '%s(%s%s)',
                $expression->name,
                $expression->distinct ? 'DISTINCT ' : '',
                implode(', ', array_map($this->print(...), $expression->arguments)),
            ),
            !$this->forExplain && ($expression instanceof Star || $expression instanceof Placeholder
                || $expression instanceof InSubquery || $expression instanceof ScalarSubquery) => throw new ExecutionException(
                    sprintf('%s cannot appear in this expression.', $expression::class),
                ),
            $expression instanceof Placeholder => '?',
            $expression instanceof Star => $expression->qualifier !== null ? sprintf('%s.*', $expression->qualifier) : '*',
            $expression instanceof InSubquery, $expression instanceof ScalarSubquery => '(subquery)',
            default => throw new ExecutionException(sprintf('Cannot print expression of type %s.', $expression::class)),
        };
    }

    /** Parentheses around one operator's text, unless only a person will read it. */
    private function group(string $text): string
    {
        return $this->forExplain ? $text : "({$text})";
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
}

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
 * `Parser`'s dual: turns an `Expression` back into SQL text that
 * `Parser::parseOne()` reparses to an equivalent tree.
 *
 * The one place this is needed today is `CHECK (...)`: `Schema\Constraint\CheckConstraint`
 * keeps its expression as a string (a decision made before a parser
 * existed — see DECISIONS.md), so whatever builds one from a parsed
 * `CREATE TABLE` needs a text form to hand it, and this is that form.
 * `Storage\Backup\Dumper` (Phase 19) is expected to be the other caller,
 * turning a table's constraints back into the `CREATE TABLE` text a dump
 * file holds.
 *
 * Every operator's operands are parenthesized unconditionally, whether or
 * not precedence would require it. Precedence-minimal printing would need
 * this class to track the exact same precedence table as `Parser` and keep
 * the two in step by hand; over-parenthesizing needs nothing but the tree
 * itself; and correctness — the printed text always reparses to a tree
 * `Parser` agrees is equivalent — is the only property that actually
 * matters here.
 *
 * `Star`, `Placeholder` and the two subquery nodes have no legal place in a
 * `CHECK` expression and are refused rather than printed.
 */
final class ExpressionPrinter
{
    public function print(Expression $expression): string
    {
        return match (true) {
            $expression instanceof Literal => $this->literal($expression->value),
            $expression instanceof ColumnRef => $expression->qualifier !== null
                ? sprintf('%s.%s', $expression->qualifier, $expression->column)
                : $expression->column,
            $expression instanceof BinaryOp => sprintf(
                '(%s %s %s)',
                $this->print($expression->left),
                $expression->operator->symbol(),
                $this->print($expression->right),
            ),
            $expression instanceof UnaryOp => sprintf(
                '%s (%s)',
                $expression->operator === UnaryOperator::NOT ? 'NOT' : '-',
                $this->print($expression->operand),
            ),
            $expression instanceof Between => sprintf(
                '(%s %sBETWEEN %s AND %s)',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
                $this->print($expression->low),
                $this->print($expression->high),
            ),
            $expression instanceof IsNull => sprintf(
                '(%s IS %sNULL)',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
            ),
            $expression instanceof Like => sprintf(
                '(%s %sLIKE %s)',
                $this->print($expression->subject),
                $expression->negated ? 'NOT ' : '',
                $this->print($expression->pattern),
            ),
            $expression instanceof InList => sprintf(
                '(%s %sIN (%s))',
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
            $expression instanceof Star, $expression instanceof Placeholder,
            $expression instanceof InSubquery, $expression instanceof ScalarSubquery => throw new ExecutionException(
                sprintf('%s cannot appear in this expression.', $expression::class),
            ),
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

}

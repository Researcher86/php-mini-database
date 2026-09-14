<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\Expression;

/**
 * Every two-operand operator the parser produces, arithmetic, comparison and
 * logical alike — one enum rather than three, because `BinaryOp` treats them
 * identically (an evaluator switches on the operator; the AST does not care
 * which family it belongs to).
 */
enum BinaryOperator
{
    case ADD;
    case SUBTRACT;
    case MULTIPLY;
    case DIVIDE;
    case MODULO;

    case EQUAL;
    case NOT_EQUAL;
    case LESS_THAN;
    case LESS_THAN_OR_EQUAL;
    case GREATER_THAN;
    case GREATER_THAN_OR_EQUAL;

    case AND;
    case OR;
}

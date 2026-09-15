<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Exception\ParserException;
use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use Throwable;

/**
 * `Execution\Executor::execute()`'s answer, turned into the `Message` a
 * client actually receives — the seam between the execution engine and the
 * wire, so neither has to know the other exists.
 *
 * `Executor::execute()` returns one of three shapes (a `QueryResult` for
 * `SELECT`, an `int` row count for `INSERT`/`UPDATE`/`DELETE`, `null` for
 * DDL and transaction control), and PLAN.md's message table has only one
 * result shape, `QUERY_RESULT` (§5.6). `encode()` represents all three
 * through it rather than inventing two more message types the table does
 * not have: an `int` becomes one column (`affected_rows`) and one row
 * naming it, and `null` becomes zero columns and zero rows — both
 * conventions a client can recognize from `count($columns)` alone, without
 * a fourth message type just to say "this statement had no rows to show."
 */
final readonly class ResultEncoder
{
    public function encode(QueryResult|int|null $result): QueryResultMessage
    {
        if ($result instanceof QueryResult) {
            return $this->encodeQueryResult($result);
        }

        if (is_int($result)) {
            return new QueryResultMessage(['affected_rows'], [[$result]]);
        }

        return new QueryResultMessage([], []);
    }

    private function encodeQueryResult(QueryResult $result): QueryResultMessage
    {
        $rows = [];

        foreach ($result->rows as $row) {
            $rows[] = array_values($row->toArray());
        }

        return new QueryResultMessage($result->columns, $rows);
    }

    public function encodeError(Throwable $error): QueryError
    {
        return new QueryError($this->errorCodeFor($error), $error->getMessage());
    }

    private function errorCodeFor(Throwable $error): ErrorCode
    {
        return match (true) {
            $error instanceof ParserException => ErrorCode::PARSER_ERROR,
            $error instanceof TypeException => ErrorCode::TYPE_MISMATCH,
            $error instanceof ConstraintViolationException => ErrorCode::CONSTRAINT_VIOLATION,
            $error instanceof TransactionException => ErrorCode::TRANSACTION_ERROR,
            $error instanceof StorageException => ErrorCode::STORAGE_ERROR,
            // SchemaException covers both a missing table and a missing
            // column - this project's exception hierarchy does not yet
            // distinguish them (see DECISIONS.md), so TABLE_NOT_FOUND is
            // the nearer of the two wire codes rather than a guess from
            // the message text.
            $error instanceof SchemaException => ErrorCode::TABLE_NOT_FOUND,
            $error instanceof ExecutionException => ErrorCode::EXECUTION_ERROR,
            default => ErrorCode::EXECUTION_ERROR,
        };
    }
}

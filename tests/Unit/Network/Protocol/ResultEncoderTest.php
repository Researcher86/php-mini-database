<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network\Protocol;

use PhpMiniDatabase\Exception\ConstraintViolationException;
use PhpMiniDatabase\Exception\ExecutionException;
use PhpMiniDatabase\Exception\ParserException;
use PhpMiniDatabase\Exception\SchemaException;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Exception\TransactionException;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Execution\QueryResult;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpMiniDatabase\Network\Protocol\ResultEncoder;
use PhpMiniDatabase\Schema\Row;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResultEncoderTest extends TestCase
{
    private ResultEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new ResultEncoder();
    }

    public function testASelectResultBecomesItsColumnsAndRows(): void
    {
        $result = new QueryResult(['id', 'name'], [new Row(['id' => 1, 'name' => 'Ann']), new Row(['id' => 2, 'name' => null])]);

        $message = $this->encoder->encode($result);

        self::assertSame(['id', 'name'], $message->columns);
        self::assertSame([[1, 'Ann'], [2, null]], $message->rows);
    }

    public function testAnAffectedRowCountBecomesOneNamedColumn(): void
    {
        $message = $this->encoder->encode(3);

        self::assertSame(['affected_rows'], $message->columns);
        self::assertSame([[3]], $message->rows);
    }

    public function testNullBecomesAnEmptyResult(): void
    {
        $message = $this->encoder->encode(null);

        self::assertSame([], $message->columns);
        self::assertSame([], $message->rows);
    }

    public function testAParserExceptionMapsToParserError(): void
    {
        $error = $this->encoder->encodeError(new ParserException('bad syntax', 1, 1));

        self::assertSame(ErrorCode::PARSER_ERROR, $error->code);
        self::assertStringContainsString('bad syntax', $error->message);
    }

    public function testASchemaExceptionMapsToTableNotFound(): void
    {
        $error = $this->encoder->encodeError(new SchemaException('no such table'));

        self::assertSame(ErrorCode::TABLE_NOT_FOUND, $error->code);
    }

    public function testATypeExceptionMapsToTypeMismatch(): void
    {
        $error = $this->encoder->encodeError(new TypeException('bad type'));

        self::assertSame(ErrorCode::TYPE_MISMATCH, $error->code);
    }

    public function testAConstraintViolationExceptionMapsToConstraintViolation(): void
    {
        $error = $this->encoder->encodeError(new ConstraintViolationException('duplicate'));

        self::assertSame(ErrorCode::CONSTRAINT_VIOLATION, $error->code);
    }

    public function testATransactionExceptionMapsToTransactionError(): void
    {
        $error = $this->encoder->encodeError(new TransactionException('no active transaction'));

        self::assertSame(ErrorCode::TRANSACTION_ERROR, $error->code);
    }

    public function testAStorageExceptionMapsToStorageError(): void
    {
        $error = $this->encoder->encodeError(new StorageException('disk full'));

        self::assertSame(ErrorCode::STORAGE_ERROR, $error->code);
    }

    public function testAnExecutionExceptionMapsToExecutionError(): void
    {
        $error = $this->encoder->encodeError(new ExecutionException('ambiguous column'));

        self::assertSame(ErrorCode::EXECUTION_ERROR, $error->code);
    }

    public function testAnUnrecognizedThrowableFallsBackToExecutionError(): void
    {
        $error = $this->encoder->encodeError(new RuntimeException('something else entirely'));

        self::assertSame(ErrorCode::EXECUTION_ERROR, $error->code);
        self::assertSame('something else entirely', $error->message);
    }
}

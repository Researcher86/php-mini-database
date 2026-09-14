<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * `ROLLBACK` / `ROLLBACK WORK` / `ROLLBACK TRANSACTION` — a full rollback.
 * `ROLLBACK TO SAVEPOINT name` is `RollbackToSavepointStatement` instead,
 * since it undoes only part of the transaction and leaves it open.
 */
final readonly class RollbackStatement implements Statement
{
}

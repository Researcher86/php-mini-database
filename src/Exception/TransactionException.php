<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * Raised for anything transaction-shaped going wrong: a statement that
 * needs an active transaction finding none (`COMMIT` with nothing to
 * commit), a `SAVEPOINT` named that does not exist, or a lock a
 * transaction cannot get because another one already holds it.
 */
final class TransactionException extends DatabaseException
{
}

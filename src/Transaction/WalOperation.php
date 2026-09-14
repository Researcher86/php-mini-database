<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

/** Every shape of entry the WAL records, per PLAN.md §6.5's own log format. */
enum WalOperation: string
{
    case BEGIN = 'BEGIN';
    case COMMIT = 'COMMIT';
    case ROLLBACK = 'ROLLBACK';
    case INSERT = 'INSERT';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case SAVEPOINT = 'SAVEPOINT';
    case RELEASE_SAVEPOINT = 'RELEASE_SAVEPOINT';
    case ROLLBACK_TO_SAVEPOINT = 'ROLLBACK_TO_SAVEPOINT';
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Transaction;

/**
 * SHARED locks coexist with any number of other SHARED locks on the same
 * resource; EXCLUSIVE excludes every other lock, shared or exclusive. The
 * standard two-mode lock, same semantics as `Infrastructure\FileLock`'s
 * shared/exclusive pair, just held in memory against a table or row rather
 * than a file.
 */
enum LockMode
{
    case SHARED;
    case EXCLUSIVE;
}

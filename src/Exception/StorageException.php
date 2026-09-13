<?php

declare(strict_types=1);

namespace MiniDatabase\Exception;

/**
 * Raised when the on-disk or in-memory byte stream is not what a storage
 * structure expects (record too short, page out of range, corrupted length
 * prefix). A well-behaved caller reports it as a storage error rather than
 * pretending the data is fine.
 */
final class StorageException extends DatabaseException
{
}

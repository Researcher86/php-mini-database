<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/** The server itself cannot start or keep running — a bind failure, above all. */
final class ServerException extends DatabaseException
{
}

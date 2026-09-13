<?php

declare(strict_types=1);

namespace MiniDatabase\Exception;

use RuntimeException;

/**
 * The root of every error the database can raise. Catching this one type is
 * enough for the edge of the system (server, client, CLI) to react to any
 * failure without knowing each subsystem's leaf exceptions.
 */
class DatabaseException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

/**
 * A `QueryError`'s `$code` — PLAN.md §5.7 and §19's table, one case per
 * row, backed by that row's byte.
 *
 * `EXECUTION_ERROR` is not in PLAN.md's table: the table has no code for
 * "syntactically valid SQL that cannot run" — an ambiguous unqualified
 * column, `GROUP BY` without a `FROM`, a statement outside any open
 * transaction — which is exactly what `Exception\ExecutionException`
 * covers and none of the other eleven codes fit. Added here rather than
 * stretched to fit `PARSER_ERROR` (a real syntax problem) or left
 * unencodable. See DECISIONS.md.
 *
 * `DEADLOCK`, `AUTH_FAILED`, `PERMISSION_DENIED`, `QUERY_CANCELLED` and
 * `TIMEOUT` have no `Exception\*` class mapped to them yet in
 * `ResultEncoder::errorCodeFor()` — this project has no deadlock
 * detection, connection-level auth, permissions, cancellation or timeouts
 * to raise them from before a server exists (Milestones 12–13). The codes
 * are defined now, since they are part of the wire format PLAN.md fixes,
 * not earned only once something can send them.
 */
enum ErrorCode: int
{
    case PARSER_ERROR = 0x01;
    case TABLE_NOT_FOUND = 0x02;
    case COLUMN_NOT_FOUND = 0x03;
    case TYPE_MISMATCH = 0x04;
    case CONSTRAINT_VIOLATION = 0x05;
    case TRANSACTION_ERROR = 0x06;
    case DEADLOCK = 0x07;
    case STORAGE_ERROR = 0x08;
    case AUTH_FAILED = 0x09;
    case PERMISSION_DENIED = 0x0A;
    case QUERY_CANCELLED = 0x0B;
    case TIMEOUT = 0x0C;
    case EXECUTION_ERROR = 0x0D;
}

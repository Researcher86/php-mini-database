<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

/**
 * Every kind of message the wire protocol carries — PLAN.md §5.3's table,
 * one case per row, backed by that row's code so `$type->value` is exactly
 * the byte a `Frame`'s `type` field holds.
 *
 * This is also what PLAN.md's own file layout calls `Opcode` alongside
 * `MessageType` (`Network/Protocol/MessageType.php` and `Opcode.php` as two
 * separate files). There is only one enum here, not two: nothing in the
 * protocol distinguishes "which message" from "which operation" — the two
 * would be the same 25 cases under different names. See DECISIONS.md.
 */
enum MessageType: int
{
    // Handshake and authentication
    case HELLO = 0x01;
    case HELLO_ACK = 0x02;
    case AUTH = 0x03;
    case AUTH_OK = 0x04;
    case AUTH_FAIL = 0x05;

    // Queries and prepared statements
    case QUERY = 0x10;
    case QUERY_RESULT = 0x11;
    case QUERY_ERROR = 0x12;
    case PREPARE = 0x13;
    case PREPARE_OK = 0x14;
    case EXECUTE = 0x15;
    case CLOSE_STMT = 0x16;
    case COPY_IN = 0x17;
    case COPY_OUT = 0x18;

    // Transactions
    case BEGIN = 0x20;
    case COMMIT = 0x21;
    case ROLLBACK = 0x22;
    case SAVEPOINT = 0x23;

    // Keepalive
    case PING = 0x30;
    case PONG = 0x31;

    // Control
    case CANCEL = 0x40;
    case GOODBYE = 0x50;

    // Administration
    case SHOW_STATUS = 0x60;
    case SHOW_CONNECTIONS = 0x61;
    case KILL = 0x62;
}

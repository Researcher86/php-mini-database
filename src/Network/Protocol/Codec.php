<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

use PhpMiniDatabase\Network\Protocol\Message\Auth;
use PhpMiniDatabase\Network\Protocol\Message\AuthFail;
use PhpMiniDatabase\Network\Protocol\Message\AuthOk;
use PhpMiniDatabase\Network\Protocol\Message\Begin;
use PhpMiniDatabase\Network\Protocol\Message\Cancel;
use PhpMiniDatabase\Network\Protocol\Message\CloseStatement;
use PhpMiniDatabase\Network\Protocol\Message\Commit;
use PhpMiniDatabase\Network\Protocol\Message\CopyIn;
use PhpMiniDatabase\Network\Protocol\Message\CopyOut;
use PhpMiniDatabase\Network\Protocol\Message\Execute;
use PhpMiniDatabase\Network\Protocol\Message\Goodbye;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Kill;
use PhpMiniDatabase\Network\Protocol\Message\Ping;
use PhpMiniDatabase\Network\Protocol\Message\Pong;
use PhpMiniDatabase\Network\Protocol\Message\Prepare;
use PhpMiniDatabase\Network\Protocol\Message\PrepareOk;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryError;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Protocol\Message\Rollback;
use PhpMiniDatabase\Network\Protocol\Message\Savepoint;
use PhpMiniDatabase\Network\Protocol\Message\ShowConnections;
use PhpMiniDatabase\Network\Protocol\Message\ShowStatus;

/**
 * `Message` ↔ `Frame`, the whole point of everything else in this
 * namespace: `encode()` is `new Frame($message->type(), $message->payload())`,
 * and `decode()` dispatches on `$frame->type` to the one `Message` subtype
 * that code belongs to, the same way `Sql\Parser::statement()` dispatches
 * on a `TokenType`.
 */
final class Codec
{
    public function encode(Message $message): Frame
    {
        return new Frame($message->type(), $message->payload());
    }

    public function decode(Frame $frame): Message
    {
        return match ($frame->type) {
            MessageType::HELLO => Hello::fromPayload($frame->payload),
            MessageType::HELLO_ACK => HelloAck::fromPayload($frame->payload),
            MessageType::AUTH => Auth::fromPayload($frame->payload),
            MessageType::AUTH_OK => AuthOk::fromPayload($frame->payload),
            MessageType::AUTH_FAIL => AuthFail::fromPayload($frame->payload),
            MessageType::QUERY => Query::fromPayload($frame->payload),
            MessageType::QUERY_RESULT => QueryResultMessage::fromPayload($frame->payload),
            MessageType::QUERY_ERROR => QueryError::fromPayload($frame->payload),
            MessageType::PREPARE => Prepare::fromPayload($frame->payload),
            MessageType::PREPARE_OK => PrepareOk::fromPayload($frame->payload),
            MessageType::EXECUTE => Execute::fromPayload($frame->payload),
            MessageType::CLOSE_STMT => CloseStatement::fromPayload($frame->payload),
            MessageType::COPY_IN => CopyIn::fromPayload($frame->payload),
            MessageType::COPY_OUT => CopyOut::fromPayload($frame->payload),
            MessageType::BEGIN => Begin::fromPayload($frame->payload),
            MessageType::COMMIT => Commit::fromPayload($frame->payload),
            MessageType::ROLLBACK => Rollback::fromPayload($frame->payload),
            MessageType::SAVEPOINT => Savepoint::fromPayload($frame->payload),
            MessageType::PING => Ping::fromPayload($frame->payload),
            MessageType::PONG => Pong::fromPayload($frame->payload),
            MessageType::CANCEL => Cancel::fromPayload($frame->payload),
            MessageType::GOODBYE => Goodbye::fromPayload($frame->payload),
            MessageType::SHOW_STATUS => ShowStatus::fromPayload($frame->payload),
            MessageType::SHOW_CONNECTIONS => ShowConnections::fromPayload($frame->payload),
            MessageType::KILL => Kill::fromPayload($frame->payload),
        };
    }
}

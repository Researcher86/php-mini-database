<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Client;

/**
 * A server to connect to, and how patiently to wait for it — PLAN.md
 * §8.2's shape, minus `$database`: nothing on the wire ever names one
 * (`Message\Hello`/`Auth` carry no such field), since one server process
 * serves exactly one `Schema\Database` opened from its own data directory
 * (`ServerConfig::$dataDirectory`) — there is no second database on the
 * same connection to select between. See DECISIONS.md.
 *
 * `$user`/`$password` default to `''`: a server with `authEnabled: false`
 * (Milestone 12's "dev mode", still the default) needs neither, and
 * `Connection::connect()` only sends `Auth` at all once `HELLO_ACK` names
 * `challenge_response` as the method.
 *
 * Three timeouts, not one, because they guard different failures:
 * `$connectTimeoutSeconds` bounds the initial TCP handshake (passed
 * straight to `stream_socket_client()`'s own timeout parameter);
 * `$readTimeoutSeconds` bounds how long `Connection` waits for a reply
 * once a message is sent (a hung or overloaded server, not a dead one —
 * the TCP connection itself is still up); `$writeTimeoutSeconds` bounds
 * how long sending a message may block waiting for socket buffer space
 * (relevant mainly for a very large `QUERY`/`EXECUTE` payload against a
 * server that is not reading).
 */
final readonly class ClientConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 5433,
        public string $user = '',
        public string $password = '',
        public float $connectTimeoutSeconds = 5.0,
        public float $readTimeoutSeconds = 30.0,
        public float $writeTimeoutSeconds = 30.0,
    ) {
    }
}

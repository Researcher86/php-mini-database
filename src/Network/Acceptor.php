<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use PhpMiniDatabase\Exception\ServerException;

/**
 * The listening socket: bound and put in non-blocking mode once, at
 * construction, so `accept()` — called only once `EventLoop` has already
 * seen this socket become readable — never blocks waiting for a connection
 * that `stream_select()` already promised is there.
 */
final class Acceptor
{
    /** @var resource */
    private mixed $socket;

    public function __construct(ServerConfig $config)
    {
        $address = sprintf('tcp://%s:%d', $config->host, $config->port);
        $context = stream_context_create(['socket' => ['backlog' => $config->backlog]]);

        $socket = @stream_socket_server(
            $address,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw new ServerException(sprintf('Cannot listen on %s: %s (%d).', $address, $errorMessage, $errorCode));
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    /** @return resource */
    public function socket(): mixed
    {
        return $this->socket;
    }

    /** The address actually bound — resolves `port: 0`'s OS-assigned port. */
    public function localAddress(): string
    {
        return (string) stream_socket_get_name($this->socket, false);
    }

    /**
     * The next waiting connection, already non-blocking, or `null` if none
     * is ready after all (a spurious wakeup, or another callback in the
     * same `EventLoop::tick()` batch already took it — neither should
     * happen with one `Acceptor` per socket, but `stream_socket_accept()`
     * itself can still return `false`).
     *
     * @return resource|null
     */
    public function accept(): mixed
    {
        $connection = @stream_socket_accept($this->socket, 0);

        if ($connection === false) {
            return null;
        }

        stream_set_blocking($connection, false);

        return $connection;
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}

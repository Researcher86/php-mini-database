<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Infrastructure\Logger;
use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\Frame;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Goodbye;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Ping;
use PhpMiniDatabase\Network\Protocol\Message\Pong;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\ResultEncoder;
use Throwable;

/**
 * One client connection: its socket, and everything it takes to turn
 * incoming bytes into `Execution\Executor::run()` calls and outgoing
 * bytes. `EventLoop` owns *when* `handleReadable()` runs (once its socket
 * is readable); this class owns everything about *what happens* once it
 * does.
 *
 * `Auth`/`Prepare`/`Execute`/`Begin`/`Commit`/`Rollback`/`Savepoint` — every
 * `Message` type this phase does not handle — close the connection rather
 * than being silently ignored: `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT`
 * already work today, but only as plain SQL text through `Query` (`Executor`
 * has parsed and run them since Phase 8) — the *typed* wire messages for
 * them are Milestone 15's job, once a session has more reason to prefer
 * them over SQL text. Authentication is "dev mode" (PLAN.md §11): `HELLO`
 * is answered immediately, with no `Auth` exchange at all.
 *
 * A response is written in one `fwrite()` call, no write buffering or
 * write-readiness registration — correct for the size of a response this
 * phase actually produces, and a named gap for the day a `QUERY_RESULT`
 * large enough not to fit in one non-blocking write exists.
 */
final class Session
{
    private readonly FrameReader $reader;

    private readonly Codec $codec;

    private readonly ResultEncoder $resultEncoder;

    private bool $readyForQueries = false;

    private bool $closed = false;

    /** @param resource $socket */
    public function __construct(
        private mixed $socket,
        public readonly int $id,
        private readonly Executor $executor,
        private readonly Logger $logger = new Logger(),
    ) {
        $this->reader = new FrameReader();
        $this->codec = new Codec();
        $this->resultEncoder = new ResultEncoder();
    }

    /** @return resource */
    public function socket(): mixed
    {
        return $this->socket;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function handleReadable(): void
    {
        $chunk = @fread($this->socket, 65536);

        if ($chunk === false || $chunk === '') {
            $this->close();

            return;
        }

        $this->reader->feed($chunk);

        try {
            while (($frame = $this->reader->next()) !== null) {
                $this->handleFrame($frame);

                if ($this->closed) {
                    return;
                }
            }
        } catch (ProtocolException $e) {
            $this->logger->warning(sprintf('Session %d sent a malformed frame: %s', $this->id, $e->getMessage()));
            $this->close();
        }
    }

    private function handleFrame(Frame $frame): void
    {
        $message = $this->codec->decode($frame);

        match (true) {
            $message instanceof Hello => $this->handleHello(),
            $message instanceof Query => $this->handleQuery($message),
            $message instanceof Ping => $this->send(new Pong()),
            $message instanceof Goodbye => $this->close(),
            default => $this->rejectUnsupported($message),
        };
    }

    private function handleHello(): void
    {
        $this->send(new HelloAck(Server::VERSION, 'none', ''));
        $this->readyForQueries = true;
    }

    private function handleQuery(Query $query): void
    {
        if (!$this->readyForQueries) {
            $this->rejectUnsupported($query);

            return;
        }

        try {
            $result = $this->executor->run($query->sql, $query->parameters);
            $this->send($this->resultEncoder->encode($result));
        } catch (Throwable $e) {
            $this->send($this->resultEncoder->encodeError($e));
        }
    }

    private function rejectUnsupported(Message $message): void
    {
        $this->logger->warning(sprintf(
            'Session %d sent %s, which is not supported yet; closing the connection.',
            $this->id,
            $message::class,
        ));
        $this->close();
    }

    private function send(Message $message): void
    {
        $bytes = $this->codec->encode($message)->toBytes();
        $written = @fwrite($this->socket, $bytes);

        if ($written !== strlen($bytes)) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        fclose($this->socket);
    }
}

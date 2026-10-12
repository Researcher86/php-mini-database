<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network;

use Closure;

/**
 * A single-threaded reactor: any number of readable streams, each with its
 * own callback, watched together through one `stream_select()` call per
 * `tick()`. This is PLAN.md §2.2's "event loop" half of "fork/process pool
 * or event loop" — chosen over forking because forking is explicitly
 * weaker on Windows per that same line, and because one process serving
 * every session keeps `Schema\Database`'s single-writer state (Phase 8) in
 * one place without needing shared memory between workers.
 *
 * `tick()` is the primitive everything else is built from: `run()` is
 * `while (!stopped) { tick(); }`, and a test drives the exact same method
 * directly, one call at a time, against a real socket — no different from
 * how a real client would see the server behave, just paced by the test
 * instead of by `stream_select()`'s own timeout.
 *
 * `onTick()` callbacks run at the end of every `tick()`, whether or not
 * any stream became readable — the hook for work driven by elapsed time
 * rather than by I/O (`Server`'s idle-connection sweep). `tick()`'s own
 * timeout bounds how late such work can run: with nothing readable,
 * `stream_select()` still returns once the timeout elapses, so a
 * time-driven check is never more than one tick behind.
 */
final class EventLoop
{
    /**
     * @var array<int, array{resource: resource, callback: Closure(resource): void}>
     */
    private array $readers = [];

    /** @var list<Closure(): void> */
    private array $tickCallbacks = [];

    private bool $stopped = false;

    /** @param Closure(resource): void $callback */
    public function onReadable(mixed $stream, Closure $callback): void
    {
        $this->readers[(int) $stream] = ['resource' => $stream, 'callback' => $callback];
    }

    public function removeReadable(mixed $stream): void
    {
        unset($this->readers[(int) $stream]);
    }

    /** @param Closure(): void $callback */
    public function onTick(Closure $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    /**
     * One pass: wait up to `$timeoutSeconds` for any watched stream to
     * become readable, then run every ready one's callback, then every
     * `onTick()` callback. Waits for nothing, immediately, if nothing is
     * watched at all — `stream_select()` cannot be called with every set
     * empty.
     */
    public function tick(float $timeoutSeconds = 1.0): void
    {
        $this->dispatchReadable($timeoutSeconds);

        foreach ($this->tickCallbacks as $callback) {
            $callback();
        }
    }

    private function dispatchReadable(float $timeoutSeconds): void
    {
        if ($this->readers === []) {
            return;
        }

        $read = array_map(static fn (array $entry): mixed => $entry['resource'], $this->readers);
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSeconds);
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $entry = $this->readers[(int) $stream] ?? null;

            // A callback earlier in this same batch may have already
            // removed this stream (closed the session it belonged to);
            // stream_select() already returned it as ready, so the check
            // has to happen here, not before.
            if ($entry !== null) {
                ($entry['callback'])($stream);
            }
        }
    }

    public function run(float $tickTimeoutSeconds = 1.0): void
    {
        while (!$this->stopped) {
            pcntl_signal_dispatch();
            $this->tick($tickTimeoutSeconds);
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}

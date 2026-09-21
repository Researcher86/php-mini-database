<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use PhpMiniDatabase\Infrastructure\AtomicWriter;
use PhpMiniDatabase\Infrastructure\FileSystem;

/**
 * PLAN.md §9.1's `--pid-file` — what `bin/minidb-server start` writes and
 * `stop`/`status`/`reload` read back, since those three never talk to the
 * running server over the wire at all (there is no need to: "is this pid
 * alive" and "send it a signal" are both plain OS operations). `isProcessRunning()`
 * is what lets `start` refuse to run a second time over the same data
 * directory instead of two servers fighting over one `Schema\Database`,
 * and lets a *stale* file (the process crashed or was killed without a
 * chance to remove it) be recovered from instead of blocking forever.
 */
final class PidFile
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function write(int $pid): void
    {
        (new FileSystem())->ensureDirectory(dirname($this->path));
        (new AtomicWriter())->write($this->path, $pid . "\n");
    }

    public function read(): ?int
    {
        if (!is_file($this->path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($this->path));

        return ctype_digit($contents) ? (int) $contents : null;
    }

    public function remove(): void
    {
        @unlink($this->path);
    }

    /**
     * Whether the pid this file names is a live process — `posix_kill($pid, 0)`
     * sends no actual signal, only asks the kernel whether it could. Not
     * available on a platform without `ext-posix` (PLAN.md §2.2 already
     * notes Windows' forking limitations), in which case this reports
     * `false` rather than fail outright — daemonizing was never going to
     * work there either.
     *
     * @phpstan-impure genuinely can answer differently between two calls
     *                 in the same request — `Command\ServerApplication::stop()`
     *                 polls it in a loop waiting for a process to actually
     *                 exit, which is the entire point of calling it more
     *                 than once.
     */
    public function isProcessRunning(): bool
    {
        $pid = $this->read();

        if ($pid === null) {
            return false;
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}

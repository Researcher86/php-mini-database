<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Infrastructure;

use PhpMiniDatabase\Support\Clock;
use PhpMiniDatabase\Support\SystemClock;

/**
 * One line per log entry, appended to a file — `$path === null` (the
 * default) discards everything instead, which is what every test that
 * needs a `Network\Server` but not its log output constructs. There is no
 * separate interface with a null implementation: a single class with a
 * "log nowhere" mode covers the one thing this project currently needs
 * from logging without a second file that would only ever do nothing.
 */
final class Logger
{
    public function __construct(
        private readonly ?string $path = null,
        private readonly LogLevel $minimumLevel = LogLevel::INFO,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function debug(string $message): void
    {
        $this->log(LogLevel::DEBUG, $message);
    }

    public function info(string $message): void
    {
        $this->log(LogLevel::INFO, $message);
    }

    public function warning(string $message): void
    {
        $this->log(LogLevel::WARNING, $message);
    }

    public function error(string $message): void
    {
        $this->log(LogLevel::ERROR, $message);
    }

    private function log(LogLevel $level, string $message): void
    {
        if ($this->path === null || $level->rank() < $this->minimumLevel->rank()) {
            return;
        }

        $line = sprintf(
            '[%s] %-7s %s' . PHP_EOL,
            $this->clock->now()->format('Y-m-d H:i:s'),
            strtoupper($level->value),
            $message,
        );

        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}

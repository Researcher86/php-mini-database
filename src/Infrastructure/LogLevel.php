<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Infrastructure;

enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';

    /** Higher outranks lower — what `Logger` compares against its own minimum. */
    public function rank(): int
    {
        return match ($this) {
            self::DEBUG => 0,
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3,
        };
    }
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

use InvalidArgumentException;

/** PLAN.md §9.4's `--json`/`--csv`/`--vertical` output flags; plain table is the default when none is given. */
enum OutputFormat
{
    case TABLE;
    case JSON;
    case CSV;
    case VERTICAL;

    /** @param array<string, bool> $flags as `ArgvParser::parse()` returns them */
    public static function fromFlags(array $flags): self
    {
        $named = ['json' => self::JSON, 'csv' => self::CSV, 'vertical' => self::VERTICAL];
        $chosen = array_intersect_key($named, $flags);

        if (count($chosen) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Only one output format flag may be given (got %s).',
                implode(', ', array_map(static fn (string $name): string => "--{$name}", array_keys($chosen))),
            ));
        }

        return array_values($chosen)[0] ?? self::TABLE;
    }
}

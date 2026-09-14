<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Exception;

/**
 * Raised by the lexer or the parser, always with a position in the source
 * text — a byte offset translated to a 1-based line and column, the form a
 * human reading the original SQL expects an error to point at.
 *
 * The properties are `$sourceLine` / `$sourceColumn`, not `$line` — `line`
 * is already `\Exception::$line`, the PHP source line this exception was
 * thrown from, and redeclaring it readonly here does not override it, it
 * fatals.
 */
final class ParserException extends DatabaseException
{
    public function __construct(
        string $message,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
    ) {
        parent::__construct(sprintf('%s (line %d, column %d)', $message, $sourceLine, $sourceColumn));
    }

    public static function at(string $source, int $position, string $message): self
    {
        [$line, $column] = self::lineAndColumn($source, $position);

        return new self($message, $line, $column);
    }

    /**
     * @return array{0: int, 1: int} 1-based line, then 1-based column
     */
    private static function lineAndColumn(string $source, int $position): array
    {
        $before = substr($source, 0, $position);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $position - ($lastNewline === false ? -1 : $lastNewline);

        return [$line, $column];
    }
}

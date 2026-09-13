<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;

/**
 * A boolean, stored as a single byte 0x00/0x01. cast() accepts PHP bools and
 * the small set of string spellings a user or the parser might hand over
 * ("true"/"false", "1"/"0", including case variants).
 */
final class BoolType implements Type
{
    public const CODE = 5;
    public const NAME = 'BOOL';

    public function name(): string
    {
        return self::NAME;
    }

    public function code(): int
    {
        return self::CODE;
    }

    public function cast(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', 't', '1' => true,
                'false', 'f', '0' => false,
                default => throw new TypeException(sprintf('Cannot cast %s to %s.', $value, self::NAME)),
            };
        }

        throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), self::NAME));
    }

    public function encode(mixed $value): string
    {
        return $value ? "\x01" : "\x00";
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) <= $offset) {
            throw new TypeException('BOOL value is missing a byte in the buffer.');
        }

        return [$bytes[$offset] === "\x01", $offset + 1];
    }
}

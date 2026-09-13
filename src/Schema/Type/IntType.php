<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;

/**
 * A signed 32-bit integer. Encoded as four big-endian bytes in a biased form:
 * the two's-complement representation with the sign bit flipped. That keeps
 * the bytes in ascending numeric order — -1 sorts before 0 sorts before 1 —
 * which the B-Tree relies on when keys share a common prefix encoding.
 */
final class IntType implements Type
{
    public const CODE = 1;
    public const NAME = 'INT';

    private const MIN = -2147483648;
    private const MAX = 2147483647;

    public function name(): string
    {
        return self::NAME;
    }

    public function code(): int
    {
        return self::CODE;
    }

    public function cast(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1) {
            $int = (int) $value;
        } elseif (is_float($value) && $value === floor($value) && $value >= self::MIN && $value <= self::MAX) {
            $int = (int) $value;
        } else {
            throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), self::NAME));
        }

        if ($int < self::MIN || $int > self::MAX) {
            throw new TypeException(sprintf('Value %d is out of range for %s.', $int, self::NAME));
        }

        return $int;
    }

    public function encode(mixed $value): string
    {
        return pack('N', ($value & 0xffffffff) ^ 0x80000000);
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 4) {
            throw new TypeException('INT value is missing 4 bytes in the buffer.');
        }

        $unsigned = unpack('N', substr($bytes, $offset, 4))[1] ^ 0x80000000;

        return [$unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned, $offset + 4];
    }
}

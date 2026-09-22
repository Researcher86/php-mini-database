<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Support\Binary;

/**
 * A signed 64-bit integer, the natural PHP int. Encoded as eight big-endian
 * bytes in a biased form — two's complement with the sign bit flipped — so the
 * bytes are lexicographically ordered by value across the whole signed range.
 * PHP stores ints as two's complement already, so the flip is a single XOR
 * with PHP_INT_MIN, and pack('J') writes it out byte-exactly.
 */
final class BigIntType implements Type
{
    public const CODE = 2;
    public const NAME = 'BIGINT';

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
            return $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1) {
            $int = (int) $value;

            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new TypeException(sprintf('Value %s is out of range for %s.', $value, self::NAME));
            }

            return $int;
        }

        if (is_float($value) && $value === floor($value) && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX) {
            return (int) $value;
        }

        throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), self::NAME));
    }

    public function encode(mixed $value): string
    {
        return pack('J', $value ^ PHP_INT_MIN);
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 8) {
            throw new TypeException('BIGINT value is missing 8 bytes in the buffer.');
        }

        return [Binary::unpackInt('J', $bytes, $offset) ^ PHP_INT_MIN, $offset + 8];
    }
}

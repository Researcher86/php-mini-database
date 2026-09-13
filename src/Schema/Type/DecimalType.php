<?php

declare(strict_types=1);

namespace MiniDatabase\Schema\Type;

use MiniDatabase\Exception\TypeException;

/**
 * An exact decimal, DECIMAL(precision, scale). The canonical value a user
 * sees is a fixed-scale string (DECIMAL(10,2) is "123.40", never "123.4"),
 * but the bytes are a scaled integer — value * 10^scale — encoded as int64
 * big-endian. Fixed-point bytes stay sortable, which the B-Tree will rely on
 * later, and exact, which arithmetic comparisons between decimals rely on.
 *
 * Precision is capped at 18 digits so the scaled integer always fits a
 * 64-bit PHP int.
 */
final class DecimalType implements Type
{
    public const CODE = 4;

    /** Powers of ten held as int64, indexed by exponent. */
    private const POW10 = [
        1, 10, 100, 1000, 10000, 100000, 1000000, 10000000, 100000000,
        1000000000, 10000000000, 100000000000, 1000000000000, 10000000000000,
        100000000000000, 1000000000000000, 10000000000000000, 100000000000000000,
    ];

    public function __construct(
        private readonly int $precision,
        private readonly int $scale,
    ) {
        if ($precision < 1 || $precision > 18) {
            throw new TypeException('DECIMAL precision must be between 1 and 18.');
        }
        if ($scale < 0 || $scale > $precision) {
            throw new TypeException('DECIMAL scale must be between 0 and precision.');
        }
    }

    public function name(): string
    {
        return sprintf('DECIMAL(%d,%d)', $this->precision, $this->scale);
    }

    public function code(): int
    {
        return self::CODE;
    }

    public function cast(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            $value = sprintf('%.*F', $this->scale, $value);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || preg_match('/^[+-]?\d+(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), $this->name()));
        }

        [$sign, $integer, $fraction] = $this->split($value);

        $integerDigits = strlen($integer);
        $fractionDigits = strlen($fraction);
        $maxIntegerDigits = $this->precision - $this->scale;

        if ($fractionDigits > $this->scale) {
            throw new TypeException(sprintf('%d fraction digits exceed %s scale.', $fractionDigits, $this->name()));
        }
        if ($integerDigits > $maxIntegerDigits) {
            throw new TypeException(sprintf('%d integer digits exceed %s capacity.', $integerDigits, $this->name()));
        }

        $fraction = str_pad($fraction, $this->scale, '0');

        return $sign . $integer . ($this->scale > 0 ? '.' . $fraction : '');
    }

    public function encode(mixed $value): string
    {
        [$sign, $integer, $fraction] = $this->split($value);

        $scaled = (int) $integer * self::POW10[$this->scale] + (int) $fraction;

        return pack('J', ($sign === '-' ? -$scaled : $scaled) ^ PHP_INT_MIN);
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 8) {
            throw new TypeException(sprintf('%s value is missing 8 bytes in the buffer.', $this->name()));
        }

        $scaled = unpack('J', substr($bytes, $offset, 8))[1] ^ PHP_INT_MIN;
        $sign = $scaled < 0 ? '-' : '';
        $scaled = abs($scaled);

        $integer = (int) intdiv($scaled, self::POW10[$this->scale]);
        $fraction = $scaled % self::POW10[$this->scale];

        if ($this->scale === 0) {
            return [$sign . $integer, $offset + 8];
        }

        return [$sign . $integer . '.' . str_pad((string) $fraction, $this->scale, '0', STR_PAD_LEFT), $offset + 8];
    }

    /**
     * Break a numeric string into its sign and its zero-stripped integer and
     * fraction parts. The integer part is normalized so "007" becomes "7",
     * which stops leading zeros from being counted against precision.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function split(string $value): array
    {
        preg_match('/^([+-])?(\d+)(?:\.(\d+))?$/', $value, $m);

        $integer = ltrim($m[2], '0');
        $integer = $integer === '' ? '0' : $integer;

        return [$m[1] ?? '', $integer, $m[3] ?? ''];
    }
}

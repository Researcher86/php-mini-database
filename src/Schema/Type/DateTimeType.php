<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Support\Binary;

/**
 * A point in time with up to microsecond precision, stored as an instant —
 * the byte form is microseconds since the Unix epoch as biased int64 (sign
 * bit flipped) big endian, so the value is independent of calendars and
 * timezones and still sorts by byte value. The canonical value is a
 * DateTimeImmutable; cast() also accepts "YYYY-MM-DD HH:MM:SS[.ffffff]"
 * strings.
 */
final class DateTimeType implements Type
{
    public const CODE = 7;
    public const NAME = 'DATETIME';

    private const MICROS_PER_SECOND = 1000000;

    public function name(): string
    {
        return self::NAME;
    }

    public function code(): int
    {
        return self::CODE;
    }

    public function cast(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            foreach (['Y-m-d H:i:s.u', 'Y-m-d\TH:i:s.u', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

                if ($date !== false && strtolower($date->format($format)) === strtolower($value)) {
                    return $date;
                }
            }

            throw new TypeException(sprintf('Cannot cast %s to %s.', $value, self::NAME));
        }

        throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), self::NAME));
    }

    public function encode(mixed $value): string
    {
        $micros = $value->getTimestamp() * self::MICROS_PER_SECOND + (int) $value->format('u');

        return pack('J', $micros ^ PHP_INT_MIN);
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 8) {
            throw new TypeException(sprintf('%s value is missing 8 bytes in the buffer.', self::NAME));
        }

        $micros = Binary::unpackInt('J', $bytes, $offset) ^ PHP_INT_MIN;

        // PHP's % and intdiv divide toward zero, so a negative micros value
        // must be normalized to floor-seconds + a positive fraction before it
        // becomes a "U.u" string. Skipping that turns -1us into "+0.000001".
        $fraction = $micros % self::MICROS_PER_SECOND;
        if ($fraction < 0) {
            $fraction += self::MICROS_PER_SECOND;
        }
        $seconds = intdiv($micros - $fraction, self::MICROS_PER_SECOND);

        $date = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $fraction),
            new DateTimeZone('UTC'),
        );

        if ($date === false) {
            throw new TypeException(sprintf('Cannot reconstruct a datetime from micros %s.', $micros));
        }

        return [$date, $offset + 8];
    }
}

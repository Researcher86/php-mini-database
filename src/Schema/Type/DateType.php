<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Support\Binary;

/**
 * A calendar date without a time component. The canonical value is a
 * DateTimeImmutable at UTC midnight; the bytes are the day count since the
 * Unix epoch as a biased signed int32 (sign bit flipped), which keeps dates
 * tiny and ordered. cast() takes a DateTimeInterface (its calendar date wins,
 * in its own timezone) or a strict "YYYY-MM-DD" string.
 */
final class DateType implements Type
{
    public const CODE = 6;
    public const NAME = 'DATE';

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
            return new DateTimeImmutable($value->format('Y-m-d'), new DateTimeZone('UTC'));
        }

        if (is_string($value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new TypeException(sprintf('Cannot cast %s to %s.', $value, self::NAME));
            }

            return $date;
        }

        throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), self::NAME));
    }

    public function encode(mixed $value): string
    {
        $days = intdiv($value->getTimestamp(), 86400);

        return pack('N', ($days & 0xffffffff) ^ 0x80000000);
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 4) {
            throw new TypeException(sprintf('%s value is missing 4 bytes in the buffer.', self::NAME));
        }

        $unsigned = Binary::unpackInt('N', $bytes, $offset) ^ 0x80000000;
        $days = $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;

        $date = DateTimeImmutable::createFromFormat(
            '!U',
            (string) ($days * 86400),
            new DateTimeZone('UTC'),
        );

        if ($date === false) {
            throw new TypeException(sprintf('Cannot reconstruct a date from day count %d.', $days));
        }

        return [$date, $offset + 4];
    }
}

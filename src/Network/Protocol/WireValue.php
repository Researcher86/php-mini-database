<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

use DateTimeImmutable;
use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Schema\Type\BigIntType;
use PhpMiniDatabase\Schema\Type\DateTimeType;

/**
 * A value that carries its own type on the wire, tagged with one
 * `WireValueTag` byte — what a `QUERY`'s bound parameters and a
 * `QUERY_RESULT`'s row values both are.
 *
 * `Schema\Type\ValueCodec` (`Network\Protocol\ValueCodec`, Phase 1) is not
 * this: it encodes a value *as* a declared column `Type`, which is exactly
 * what neither of these two has — a bound parameter's type is not known
 * until it meets a column, and a `SELECT` output column just as often
 * holds a computed value (`price * qty`, `COUNT(*)`) as a stored one, with
 * no single `Schema\Type` behind it at all. Self-describing bytes sidestep
 * needing one.
 *
 * `DECIMAL` and `BLOB` need no tag of their own: a `DECIMAL` value is
 * already a plain string by the time anything outside `Schema\Type\DecimalType`
 * sees it (see DECISIONS.md, "DECIMAL is a scaled integer, not a float"),
 * and a `BLOB` is already a plain (possibly non-UTF-8) string — this is a
 * binary protocol, not `Transaction\Wal`'s JSON, so a `BLOB`'s bytes need
 * no escaping trick to travel as `STRING`, just its own length prefix.
 * `DATE` and `DATETIME` share one `DATETIME` tag: both decode to a
 * `DateTimeImmutable` (Phase 1), and nothing downstream of a value already
 * in hand needs to know which of the two column types produced it.
 */
final class WireValue
{
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => pack('C', WireValueTag::NULL->value),
            is_bool($value) => pack('C', WireValueTag::BOOL->value) . ($value ? "\x01" : "\x00"),
            is_int($value) => pack('C', WireValueTag::INT->value) . (new BigIntType())->encode($value),
            is_float($value) => pack('C', WireValueTag::FLOAT->value) . pack('E', $value),
            is_string($value) => pack('C', WireValueTag::STRING->value) . pack('N', strlen($value)) . $value,
            $value instanceof DateTimeImmutable => pack('C', WireValueTag::DATETIME->value) . (new DateTimeType())->encode($value),
            default => throw new ProtocolException(sprintf('Cannot encode a %s as a wire value.', get_debug_type($value))),
        };
    }

    /** @return array{0: mixed, 1: int} value, then the offset just past it */
    public static function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) <= $offset) {
            throw new ProtocolException('Wire value is missing its tag byte.');
        }

        $tag = WireValueTag::tryFrom(unpack('C', $bytes, $offset)[1])
            ?? throw new ProtocolException('Wire value has an unknown tag byte.');
        $offset++;

        return match ($tag) {
            WireValueTag::NULL => [null, $offset],
            WireValueTag::BOOL => self::decodeBool($bytes, $offset),
            WireValueTag::INT => (new BigIntType())->decode($bytes, $offset),
            WireValueTag::FLOAT => self::decodeFloat($bytes, $offset),
            WireValueTag::STRING => self::decodeString($bytes, $offset),
            WireValueTag::DATETIME => (new DateTimeType())->decode($bytes, $offset),
        };
    }

    /** @return array{0: bool, 1: int} */
    private static function decodeBool(string $bytes, int $offset): array
    {
        if (strlen($bytes) <= $offset) {
            throw new ProtocolException('Wire BOOL value is missing its byte.');
        }

        return [$bytes[$offset] === "\x01", $offset + 1];
    }

    /** @return array{0: float, 1: int} */
    private static function decodeFloat(string $bytes, int $offset): array
    {
        if (strlen($bytes) < $offset + 8) {
            throw new ProtocolException('Wire FLOAT value is missing 8 bytes.');
        }

        return [unpack('E', $bytes, $offset)[1], $offset + 8];
    }

    /** @return array{0: string, 1: int} */
    private static function decodeString(string $bytes, int $offset): array
    {
        if (strlen($bytes) < $offset + 4) {
            throw new ProtocolException('Wire STRING value is missing its length prefix.');
        }

        $length = unpack('N', $bytes, $offset)[1];

        if (strlen($bytes) < $offset + 4 + $length) {
            throw new ProtocolException(sprintf('Wire STRING value is missing %d byte(s) of payload.', $length));
        }

        return [substr($bytes, $offset + 4, $length), $offset + 4 + $length];
    }
}

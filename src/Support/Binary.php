<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Support;

use RuntimeException;

/**
 * `unpack()`'s one-field forms (`'N'`, `'n'`, `'C'`, `'J'`, `'E'`), used
 * throughout `Network\Protocol\*` (the wire format) and `Schema\Type\*`
 * (the disk format) — both read the same fixed-width integer/float
 * encodings (see DECISIONS.md, "One byte format for disk and wire").
 *
 * PHP's own `unpack()` returns `array|false`, `false` only when there are
 * not enough bytes at the given offset for the format to read — every
 * call site here already checks that itself first (`strlen($bytes) >=
 * offset + width`), so `false` is unreachable in practice. Narrowing it
 * once, here, is what lets every caller keep a plain `int`/`float`
 * return type instead of each repeating the same `$x = unpack(...); if
 * ($x === false) { ... }` dance — the exact shape PHPStan level 8
 * started requiring everywhere `unpack()` was called directly (see
 * DECISIONS.md).
 */
final class Binary
{
    public static function unpackInt(string $format, string $bytes, int $offset = 0): int
    {
        $value = unpack($format, $bytes, $offset);

        if ($value === false) {
            throw new RuntimeException(sprintf('unpack("%s", ...) failed at offset %d.', $format, $offset));
        }

        return $value[1];
    }

    public static function unpackFloat(string $format, string $bytes, int $offset = 0): float
    {
        $value = unpack($format, $bytes, $offset);

        if ($value === false) {
            throw new RuntimeException(sprintf('unpack("%s", ...) failed at offset %d.', $format, $offset));
        }

        return $value[1];
    }
}

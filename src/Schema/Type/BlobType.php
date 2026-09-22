<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Support\Binary;

/**
 * An opaque byte string. Encoded like VARCHAR — a uint32 length prefix then
 * the bytes — but cast() accepts only already-string values (no numeric
 * coercion), because callers that mean a BLOB should hand over bytes.
 */
final class BlobType implements Type
{
    public const CODE = 8;
    public const NAME = 'BLOB';

    public function name(): string
    {
        return self::NAME;
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

        if (!is_string($value)) {
            throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), self::NAME));
        }

        return $value;
    }

    public function encode(mixed $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 4) {
            throw new TypeException(sprintf('%s value is missing its length prefix.', self::NAME));
        }

        $length = Binary::unpackInt('N', $bytes, $offset);

        if (strlen($bytes) < $offset + 4 + $length) {
            throw new TypeException(sprintf('%s value is missing %d bytes of payload.', self::NAME, $length));
        }

        return [substr($bytes, $offset + 4, $length), $offset + 4 + $length];
    }
}

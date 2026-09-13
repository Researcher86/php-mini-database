<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;
use Stringable;

/**
 * A variable-length UTF-8 string, capped at maxLength bytes. Encoded as a
 * uint32 byte-length followed by the raw bytes — the same shape strings use
 * on the wire protocol, so one decoder serves both disk and network.
 */
final class VarcharType implements Type
{
    public const CODE = 3;

    /**
     * The longest a declared VARCHAR may be. The cap exists so the uint32
     * length prefix can never describe more bytes than a row is allowed to
     * hold, and so a malformed CREATE TABLE cannot ask for a column that
     * would not fit a page. Longer text is a BLOB.
     */
    public const MAX_LENGTH = 65535;

    public function __construct(private readonly int $maxLength)
    {
        if ($maxLength < 1 || $maxLength > self::MAX_LENGTH) {
            throw new TypeException(sprintf('VARCHAR length must be between 1 and %d.', self::MAX_LENGTH));
        }
    }

    public function name(): string
    {
        return sprintf('VARCHAR(%d)', $this->maxLength);
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

        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new TypeException(sprintf('Cannot cast %s to %s.', get_debug_type($value), $this->name()));
        }

        $string = (string) $value;

        if (strlen($string) > $this->maxLength) {
            throw new TypeException(sprintf('%d bytes exceed %s length.', strlen($string), $this->name()));
        }

        return $string;
    }

    public function encode(mixed $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    public function decode(string $bytes, int $offset = 0): array
    {
        if (strlen($bytes) < $offset + 4) {
            throw new TypeException(sprintf('%s value is missing its length prefix.', $this->name()));
        }

        $length = unpack('N', substr($bytes, $offset, 4))[1];

        if (strlen($bytes) < $offset + 4 + $length) {
            throw new TypeException(sprintf('%s value is missing %d bytes of payload.', $this->name(), $length));
        }

        return [substr($bytes, $offset + 4, $length), $offset + 4 + $length];
    }
}

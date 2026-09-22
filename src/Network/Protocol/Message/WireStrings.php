<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Support\Binary;

/**
 * The one string layout every `Message` payload uses for a plain text
 * field — a `uint32` length prefix, then that many UTF-8 bytes (PLAN.md
 * §5.1) — and the `uint16`-counted list of them a few messages need
 * (`HELLO`'s capabilities, above all). Shared here so each `Message` class
 * only has to say what its fields *are*, not how a string is framed.
 */
trait WireStrings
{
    private static function encodeString(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    /** @return array{0: string, 1: int} */
    private static function decodeString(string $bytes, int $offset): array
    {
        if (strlen($bytes) < $offset + 4) {
            throw new ProtocolException('Message is missing a string length prefix.');
        }

        $length = Binary::unpackInt('N', $bytes, $offset);

        if (strlen($bytes) < $offset + 4 + $length) {
            throw new ProtocolException(sprintf('Message is missing %d byte(s) of string payload.', $length));
        }

        return [substr($bytes, $offset + 4, $length), $offset + 4 + $length];
    }

    /** @param list<string> $values */
    private static function encodeStringList(array $values): string
    {
        $encoded = pack('n', count($values));

        foreach ($values as $value) {
            $encoded .= self::encodeString($value);
        }

        return $encoded;
    }

    /** @return array{0: list<string>, 1: int} */
    private static function decodeStringList(string $bytes, int $offset): array
    {
        if (strlen($bytes) < $offset + 2) {
            throw new ProtocolException('Message is missing a list count.');
        }

        $count = Binary::unpackInt('n', $bytes, $offset);
        $offset += 2;
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            [$value, $offset] = self::decodeString($bytes, $offset);
            $values[] = $value;
        }

        return [$values, $offset];
    }
}

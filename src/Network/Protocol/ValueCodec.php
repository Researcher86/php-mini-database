<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

use PhpMiniDatabase\Schema\Type\Type;

/**
 * The protocol's codec for column values. A Type already knows its byte form,
 * and the disk and the wire share that encoding; this class is the thin seam
 * the network layer talks to so it never depends on Schema types directly.
 *
 * Today the seam is nearly transparent — encodeValue/decodeValue delegate to
 * the Type — and that is fine. It earns its place as soon as protocol values
 * gain casts from client input (strings typed by a column) or null-aware row
 * packing, both of which belong here and not in Schema.
 */
final class ValueCodec
{
    /**
     * Encode one value for the wire as the canonical Type bytes. Input passes
     * through the type's cast first, so client-supplied strings are coerced
     * ("42" -> INT 42) before encoding.
     */
    public function encodeValue(Type $type, mixed $value): string
    {
        return $type->encode($type->cast($value));
    }

    /**
     * Read one encoded value from the wire buffer, returning the decoded
     * value and the offset just past it.
     *
     * @return array{0: mixed, 1: int} value, then next offset
     */
    public function decodeValue(Type $type, string $bytes, int $offset = 0): array
    {
        return $type->decode($bytes, $offset);
    }
}

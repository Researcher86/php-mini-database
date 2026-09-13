<?php

declare(strict_types=1);

namespace MiniDatabase\Schema\Type;

/**
 * A column type knows three things about its values:
 *
 *   1. how a raw PHP value is *cast* to the canonical in-memory form and
 *      validated (IntType rejects out-of-range ints, VarcharType enforces its
 *      length, DateType parses "2024-06-15");
 *   2. how that canonical form is *packed* into bytes for a heap record
 *      (see RecordSerializer) — big-endian, fixed-width where the type has a
 *      fixed width;
 *   3. how bytes in the same format are *unpacked* back into PHP values.
 *
 * The byte format is shared, not storage-specific: the same encoding goes on
 * disk and, later, through the wire protocol, so one codec serves both.
 *
 * Types are immutable and safe to share between tables. Where a type needs
 * parameters (VARCHAR(255), DECIMAL(10,2)) the parameters are constructor
 * values, and each parameter set is its own instance.
 */
interface Type
{
    /**
     * The SQL-facing name: "INT", "BIGINT", "VARCHAR(255)", "DECIMAL(10,2)",
     * "BOOL", "DATE", "DATETIME", "BLOB". Used when a type is written into
     * the catalog as text.
     */
    public function name(): string;

    /**
     * The stable wire code for this type. The protocol's column descriptor
     * carries a uint8 type_code, and the codec uses it to pick a Type back
     * out of a result. Codes are assigned once and never reused.
     */
    public function code(): int;

    /**
     * Coerce a raw PHP value into the canonical form this type stores and
     * returns (int, string, bool, DateTimeImmutable). Throws TypeException
     * when the value cannot be represented. NULL is not a value a type
     * accepts — a NULL column is tracked by the row's null bitmap, not by
     * the type.
     *
     * @return mixed the canonical value, or null input passed through null
     */
    public function cast(mixed $value): mixed;

    /**
     * Pack a canonical value into the type's binary form. The value must
     * already be canonical (i.e. have passed cast()): the method encodes, it
     * does not validate.
     */
    public function encode(mixed $value): string;

    /**
     * Read one value back from a byte buffer, returning the value and the
     * position just past it so a reader can continue with the next column.
     * Returns [value, nextOffset].
     *
     * @return array{0: mixed, 1: int} value, then next offset in the buffer
     */
    public function decode(string $bytes, int $offset = 0): array;
}

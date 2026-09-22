<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Exception\TypeException;
use PhpMiniDatabase\Schema\Type\Type;

/**
 * Packs a row — an ordered list of column values (NULL allowed) into the byte
 * form a heap page stores. The shape is:
 *
 *   [null bitmap][encoded column values, in order]
 *
 * The bitmap has one bit per column, ceil(columns / 8) bytes total. Bit i is
 * the (i % 8)-th bit (LSB first) of byte i / 8 and is set when column i is
 * NULL. Non-null values follow, each in the column type's own byte form.
 *
 * The layout deliberately mirrors the wire result format (uint16 column count
 * lives in the result header, not here) so a decoded heap record can be sent
 * to a client with no re-encoding of row contents.
 */
final class RecordSerializer
{
    /**
     * @param list<mixed> $values
     * @param list<Type>  $types
     */
    public function serialize(array $values, array $types): string
    {
        $this->assertColumnCount($values, $types);

        $nullBitmap = $this->encodeNullBitmap($values);
        $body = '';

        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }

            $body .= $types[$i]->encode($types[$i]->cast($value));
        }

        return $nullBitmap . $body;
    }

    /**
     * @param list<Type> $types
     *
     * @return list<mixed>
     */
    public function deserialize(string $record, array $types): array
    {
        $columns = count($types);
        $nullBitmap = substr($record, 0, (int) ceil($columns / 8));

        if (strlen($nullBitmap) !== (int) ceil($columns / 8)) {
            throw new StorageException('Record is too short for its null bitmap.');
        }

        $values = [];
        $offset = strlen($nullBitmap);

        foreach ($types as $i => $type) {
            if ($this->isNull($nullBitmap, $i)) {
                $values[] = null;
                continue;
            }

            [$value, $offset] = $type->decode($record, $offset);
            $values[] = $value;
        }

        return $values;
    }

    /** @param list<mixed> $values */
    private function encodeNullBitmap(array $values): string
    {
        $bitmap = str_repeat("\x00", (int) ceil(count($values) / 8));

        foreach ($values as $i => $value) {
            if ($value !== null) {
                continue;
            }

            // $i % 8 is always 0-7, so 1 << (...) is always 1-128 - the
            // & 0xFF is a no-op numerically, only there so PHPStan can
            // verify chr()'s int<0,255> bound itself instead of trusting it.
            $bitmap[$i >> 3] = $bitmap[$i >> 3] | chr((1 << ($i % 8)) & 0xFF);
        }

        return $bitmap;
    }

    private function isNull(string $bitmap, int $column): bool
    {
        return (ord($bitmap[$column >> 3]) & (1 << ($column % 8))) !== 0;
    }

    /**
     * @param list<mixed> $values
     * @param list<Type>  $types
     */
    private function assertColumnCount(array $values, array $types): void
    {
        if (count($values) !== count($types)) {
            throw new TypeException(sprintf(
                'RecordSerializer: %d values for %d columns.',
                count($values),
                count($types),
            ));
        }
    }
}

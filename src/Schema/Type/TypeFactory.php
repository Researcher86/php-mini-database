<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema\Type;

use PhpMiniDatabase\Exception\TypeException;

/**
 * Turns the *written* form of a type back into a Type instance.
 *
 * Two callers need this and neither should know the list of classes: the
 * catalog, which stores a column's type as the text "VARCHAR(255)", and the
 * SQL parser, which reads the same spelling out of a CREATE TABLE. Keeping
 * the mapping here means adding a type touches one file besides the type
 * itself.
 *
 * Instances are cheap and immutable, so nothing is cached: a fresh
 * VarcharType(255) costs less than the lookup that would avoid it.
 */
final class TypeFactory
{
    /**
     * Parse a type name as it appears in SQL or in the catalog. Case and
     * spacing are free ("varchar (255)" is accepted); the parameters are not.
     *
     * @throws TypeException when the name is unknown or its parameters are
     *                       malformed
     */
    public function fromName(string $name): Type
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $name) ?? '');

        return match (true) {
            $normalized === IntType::NAME => new IntType(),
            $normalized === BigIntType::NAME => new BigIntType(),
            $normalized === BoolType::NAME => new BoolType(),
            $normalized === DateType::NAME => new DateType(),
            $normalized === DateTimeType::NAME => new DateTimeType(),
            $normalized === BlobType::NAME => new BlobType(),
            // The parametrised two are matched by pattern, and the patterns
            // are anchored: "VARCHAR(255)EXTRA" is a malformed name, not a
            // VARCHAR(255) with trailing noise.
            (bool) preg_match('/^VARCHAR\((\d+)\)$/', $normalized, $m) => new VarcharType((int) $m[1]),
            (bool) preg_match('/^DECIMAL\((\d+),(\d+)\)$/', $normalized, $m) => new DecimalType((int) $m[1], (int) $m[2]),
            default => throw new TypeException(sprintf('Unknown column type "%s".', $name)),
        };
    }

    /**
     * Pick a type by its wire code, for decoding a value whose full name the
     * reader does not have.
     *
     * This works only because every code but DECIMAL's describes its own
     * bytes: an INT is four bytes whatever the column said, and a VARCHAR is
     * a length prefix plus that many bytes whatever its declared maximum.
     * DECIMAL is the exception — its scale decides where the decimal point
     * goes, and the code does not carry it — so a DECIMAL has to be
     * reconstructed from its name. Callers that may see one (the protocol's
     * column descriptor) must therefore send the name, not just the code.
     *
     * @throws TypeException when the code is unknown, or is DECIMAL's
     */
    public function fromCode(int $code): Type
    {
        return match ($code) {
            IntType::CODE => new IntType(),
            BigIntType::CODE => new BigIntType(),
            VarcharType::CODE => new VarcharType(VarcharType::MAX_LENGTH),
            BoolType::CODE => new BoolType(),
            DateType::CODE => new DateType(),
            DateTimeType::CODE => new DateTimeType(),
            BlobType::CODE => new BlobType(),
            DecimalType::CODE => throw new TypeException('DECIMAL cannot be rebuilt from its code alone; use its name.'),
            default => throw new TypeException(sprintf('Unknown type code %d.', $code)),
        };
    }
}

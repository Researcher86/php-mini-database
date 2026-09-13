<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Infrastructure;

use PhpMiniDatabase\Exception\StorageException;

/**
 * Turns names into paths, safely.
 *
 * Every file this database touches is named after something a user chose — a
 * database, a table, an index — and those names arrive from SQL text, which
 * means from the network. `CREATE TABLE "../../etc/passwd"` has to fail at
 * the point where a name becomes a path, not deeper, so both halves of that
 * live here: what a legal identifier looks like, and how segments are joined
 * without a crafted one escaping its directory.
 */
final class Path
{
    /**
     * Letters, digits and underscores, starting with a letter or an
     * underscore, up to 64 characters. Deliberately narrower than SQL's
     * quoted identifiers: these names become directory entries, and the set
     * of characters that are safe in a path on every filesystem is smaller
     * than the set SQL allows.
     */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /**
     * @throws StorageException when the name could not safely become a path
     *                          segment
     */
    public static function identifier(string $name): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new StorageException(sprintf(
                'Invalid identifier "%s": expected letters, digits and underscores, starting with a letter.',
                $name,
            ));
        }

        return $name;
    }

    /**
     * Join a base directory with further segments.
     *
     * The segments are the ones the code itself chooses — "tables",
     * "heap.dat", "wal" — plus identifiers already passed through
     * identifier(). The check below is the second lock on the same door: it
     * costs nothing and it means a path can never be built from a segment
     * carrying a separator or a parent reference, however the segment got
     * here.
     */
    public static function join(string $base, string ...$segments): string
    {
        foreach ($segments as $segment) {
            if ($segment === '' || str_contains($segment, '/') || str_contains($segment, '\\') || $segment === '..') {
                throw new StorageException(sprintf('Unsafe path segment "%s".', $segment));
            }
        }

        return rtrim($base, '/') . '/' . implode('/', $segments);
    }
}

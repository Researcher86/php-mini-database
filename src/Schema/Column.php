<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

use PhpMiniDatabase\Infrastructure\Path;
use PhpMiniDatabase\Schema\Type\Type;

/**
 * One column of a table: a name, a type, whether NULL is allowed, and an
 * optional default.
 *
 * NOT NULL and DEFAULT live here rather than as entries in `Constraint\`,
 * because the catalog's on-disk format (PLAN.md §6.3) already carries them
 * as column properties (`"not_null": true`, `"default": null`) and a value
 * that means the same thing stored two ways would only give the two copies
 * a chance to disagree.
 *
 * The default is kept exactly as it was given — not cast — so that what
 * comes back out of `rawDefault()` for the catalog to write to JSON is
 * bit-for-bit what came in, whatever the type. Casting happens on demand in
 * `defaultValue()`, through the same `Type::cast()` an inserted value goes
 * through, so a string default for a DATE column is parsed exactly as a
 * string literal in an INSERT would be.
 *
 * A column has no default at all unless `withDefault()` was used — `null` is
 * a legitimate default (a nullable column defaulting to NULL) and has to be
 * distinguishable from "no default was declared", hence `hasDefault()`
 * rather than treating a null default as absent.
 */
final readonly class Column
{
    public function __construct(
        public string $name,
        public Type $type,
        public bool $notNull = false,
        private bool $hasDefault = false,
        private mixed $rawDefault = null,
    ) {
        Path::identifier($name);
    }

    public function withDefault(mixed $default): self
    {
        return new self($this->name, $this->type, $this->notNull, hasDefault: true, rawDefault: $default);
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    /** The default, cast through this column's type. */
    public function defaultValue(): mixed
    {
        return $this->type->cast($this->rawDefault);
    }

    /** The default exactly as given, for round-tripping to the catalog. */
    public function rawDefault(): mixed
    {
        return $this->rawDefault;
    }
}

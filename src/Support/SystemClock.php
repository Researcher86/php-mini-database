<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock, always UTC — matching `Schema\Type\DateType` and
 * `DateTimeType`'s own canonical timezone, so a `CURRENT_TIMESTAMP` compares
 * correctly against a column's decoded value without either side needing to
 * convert.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

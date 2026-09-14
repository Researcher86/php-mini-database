<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Support;

use DateTimeImmutable;

/**
 * The one seam between "now" and everything that asks for it —
 * `CURRENT_TIMESTAMP`/`CURRENT_DATE` above all. A test that needs a
 * deterministic instant supplies a fake implementation instead of a query's
 * result depending on the wall clock at the moment it happened to run.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}

<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Schema;

/**
 * The database root object. Deliberately empty at Phase 0: it exists so the
 * project ships with an autoloadable class the tooling can see, before any
 * behaviour lands later. The schema milestones turn it into the owner of
 * tables and the catalog.
 */
final class Database
{
}

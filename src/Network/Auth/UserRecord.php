<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Auth;

/** One user, as `UserStore` persists and hands it back. */
final readonly class UserRecord
{
    /** @param list<string> $roles */
    public function __construct(
        public string $username,
        public string $hash,
        public array $roles = [],
    ) {
    }
}

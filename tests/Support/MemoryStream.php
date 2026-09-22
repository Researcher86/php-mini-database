<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Support;

/**
 * `fopen('php://memory', 'r+')`, narrowed — PHP types `fopen()`'s return
 * as `resource|false`, and opening an in-memory stream never actually
 * fails, but every test that captures output this way (there are many)
 * would otherwise repeat the same "if it's false, fail" dance PHPStan
 * level 8 demands before treating the result as a plain `resource`.
 */
trait MemoryStream
{
    /** @return resource */
    private function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            self::fail('Could not open a php://memory stream.');
        }

        return $stream;
    }
}

<?php

declare(strict_types=1);

namespace MiniDatabase\Tests\Support;

use MiniDatabase\Infrastructure\FileSystem;

/**
 * A scratch directory per test, removed when the test ends.
 *
 * The storage layer is about files, so its tests are about real files: there
 * is no seam to fake that would still prove a page survives being written
 * and read back. This keeps that honest and cheap — one directory under the
 * system temp dir, created in setUp and deleted in tearDown, so a test can
 * create, corrupt and delete files without arranging any of it.
 */
trait TemporaryDirectory
{
    private string $directory;

    protected function setUpTemporaryDirectory(): void
    {
        $this->directory = sys_get_temp_dir() . '/minidb-test-' . bin2hex(random_bytes(8));

        (new FileSystem())->ensureDirectory($this->directory);
    }

    protected function tearDownTemporaryDirectory(): void
    {
        (new FileSystem())->removeDirectory($this->directory);
    }

    /** A path inside the scratch directory. The file need not exist. */
    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}

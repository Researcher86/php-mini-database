<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Bin;

use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * bin/bootstrap.php's own walk-up-to-find-vendor/autoload.php logic, run as
 * a real subprocess against a constructed directory tree - not read, run: a
 * fatal error in the walk would otherwise take this test process down with
 * it, which is exactly why bootstrap.php itself is exercised through
 * proc_open rather than required directly.
 *
 * The real file under test, copied into each constructed tree so a change
 * to bootstrap.php's actual logic is what this test exercises - not a
 * second, hand-maintained copy of it that could quietly drift from the
 * real one.
 */
final class BootstrapTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testFindsTheAutoloaderOneLevelUpWhenThisRepoIsRoot(): void
    {
        // bootstrap.php's own real position: bin/bootstrap.php with
        // vendor/autoload.php exactly one directory above bin/.
        $marker = $this->buildTree($this->path('root-case'), bootstrapAt: 'bin', autoloadAt: '');

        $this->assertRequireSucceeds($this->path('root-case/bin/bootstrap.php'));
        $this->assertFileExists($marker);
    }

    public function testFindsTheConsumersAutoloaderWhenInstalledAsADependency(): void
    {
        // vendor/tanat/php-mini-database/bin/bootstrap.php, with no
        // vendor/autoload.php of its own anywhere under
        // vendor/tanat/php-mini-database/ - only the CONSUMING project's
        // own, three levels further up.
        $marker = $this->buildTree(
            $this->path('consumer'),
            bootstrapAt: 'vendor/tanat/php-mini-database/bin',
            autoloadAt: '',
        );

        $this->assertRequireSucceeds($this->path('consumer/vendor/tanat/php-mini-database/bin/bootstrap.php'));
        $this->assertFileExists($marker);
    }

    public function testFailsWithAClearMessageWhenNoAutoloaderExistsAnywhereAbove(): void
    {
        $binDir = $this->path('orphan/bin');
        mkdir($binDir, 0o777, true);
        copy(__DIR__ . '/../../../bin/bootstrap.php', $binDir . '/bootstrap.php');

        $process = proc_open(
            [PHP_BINARY, $binDir . '/bootstrap.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Could not find vendor/autoload.php', (string) $errors);
    }

    /**
     * Builds $root/$bootstrapAt/bootstrap.php (the real file, copied) plus a
     * fake $root/vendor/autoload.php that writes a marker file the moment
     * PHP requires it - proof of exactly which autoloader the walk found,
     * without needing a real Composer-generated one to make the assertion.
     *
     * @return string the marker file's path
     */
    private function buildTree(string $root, string $bootstrapAt, string $autoloadAt): string
    {
        $binDir = rtrim($root . '/' . $bootstrapAt, '/');
        mkdir($binDir, 0o777, true);
        copy(__DIR__ . '/../../../bin/bootstrap.php', $binDir . '/bootstrap.php');

        $vendorDir = rtrim($root . '/' . $autoloadAt, '/') . '/vendor';

        if (!is_dir($vendorDir)) {
            mkdir($vendorDir, 0o777, true);
        }

        $marker = $root . '/found.marker';
        file_put_contents(
            $vendorDir . '/autoload.php',
            '<?php file_put_contents(' . var_export($marker, true) . ", 'loaded');\n",
        );

        return $marker;
    }

    private function assertRequireSucceeds(string $bootstrapPath): void
    {
        $process = proc_open(
            [PHP_BINARY, $bootstrapPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $errors);
    }
}

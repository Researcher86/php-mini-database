<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Backup;

use PhpMiniDatabase\Backup\BackupManager;
use PhpMiniDatabase\Exception\StorageException;
use PhpMiniDatabase\Execution\Executor;
use PhpMiniDatabase\Infrastructure\FileSystem;
use PhpMiniDatabase\Schema\Database;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    use TemporaryDirectory;

    private BackupManager $manager;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->manager = new BackupManager();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testBackupAndRestoreRoundTripARealDatabase(): void
    {
        $dataDirectory = $this->path('mydb');
        $database = Database::open($dataDirectory);
        $executor = new Executor($database);
        $executor->run('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50))');
        $executor->run("INSERT INTO users (id, name) VALUES (1, 'Ann')");
        $database->close();

        $archivePath = $this->path('backup.tar.gz');
        $this->manager->backup($dataDirectory, $archivePath);

        self::assertFileExists($archivePath);

        $restoredDirectory = $this->path('restored');
        $this->manager->restore($archivePath, $restoredDirectory);

        $restoredDatabase = Database::open($restoredDirectory);
        $restoredExecutor = new Executor($restoredDatabase);
        $result = $restoredExecutor->run('SELECT id, name FROM users');
        $rows = iterator_to_array($result->rows, false);

        self::assertCount(1, $rows);
        self::assertSame(['id' => 1, 'name' => 'Ann'], $rows[0]->toArray());

        $restoredDatabase->close();
    }

    public function testBackupOfADatabaseWithNoTablesYetStillProducesAValidArchive(): void
    {
        // A brand new data directory (Database::open() then close(),
        // never a CREATE TABLE) has nothing in it PharData considers a
        // real entry - it used to silently never write the .tar.gz file
        // to disk at all in that case.
        $dataDirectory = $this->path('mydb');
        Database::open($dataDirectory)->close();

        $archivePath = $this->path('backup.tar.gz');
        $this->manager->backup($dataDirectory, $archivePath);

        self::assertFileExists($archivePath);

        $restoredDirectory = $this->path('restored');
        $this->manager->restore($archivePath, $restoredDirectory);

        // Database::open() recreates whatever bare structure it needs
        // from nothing regardless, so a restored, table-less database is
        // still perfectly usable.
        $restoredDatabase = Database::open($restoredDirectory);
        self::assertSame([], $restoredDatabase->tableNames());
        $restoredDatabase->close();
    }

    public function testBackupOfAMissingDirectoryFails(): void
    {
        $this->expectException(StorageException::class);

        $this->manager->backup($this->path('no-such-directory'), $this->path('out.tar.gz'));
    }

    public function testRestoreOfAMissingArchiveFails(): void
    {
        $this->expectException(StorageException::class);

        $this->manager->restore($this->path('no-such-archive.tar.gz'), $this->path('restored'));
    }

    public function testRestoreRefusesANonEmptyTargetDirectoryWithoutForce(): void
    {
        $dataDirectory = $this->path('mydb');
        $database = Database::open($dataDirectory);
        $database->close();
        $archivePath = $this->path('backup.tar.gz');
        $this->manager->backup($dataDirectory, $archivePath);

        $target = $this->path('occupied');
        (new FileSystem())->ensureDirectory($target);
        file_put_contents($target . '/something-else.txt', 'pre-existing');

        $this->expectException(StorageException::class);

        $this->manager->restore($archivePath, $target);
    }

    public function testForceAllowsRestoringOverANonEmptyDirectory(): void
    {
        $dataDirectory = $this->path('mydb');
        $database = Database::open($dataDirectory);
        $database->close();
        $archivePath = $this->path('backup.tar.gz');
        $this->manager->backup($dataDirectory, $archivePath);

        $target = $this->path('occupied');
        (new FileSystem())->ensureDirectory($target);
        file_put_contents($target . '/something-else.txt', 'pre-existing');

        $this->manager->restore($archivePath, $target, force: true);

        self::assertFileExists($target . '/something-else.txt');
    }

    public function testBackupNeverLeavesATemporaryFileBehind(): void
    {
        $dataDirectory = $this->path('mydb');
        (new FileSystem())->ensureDirectory($dataDirectory);
        file_put_contents($dataDirectory . '/marker.txt', 'x');

        $archivePath = $this->path('backup.tar.gz');
        $this->manager->backup($dataDirectory, $archivePath);

        $leftovers = glob($this->path('*.tmp.*'));
        self::assertSame([], $leftovers);
    }
}

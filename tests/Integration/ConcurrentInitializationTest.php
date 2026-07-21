<?php

namespace Alfred\Productive\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

class ConcurrentInitializationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alfred-productive-init-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    public function testConcurrentInitializationCreatesOneValidSchema(): void
    {
        $database = $this->directory . '/productive.sqlite';
        $fixture = dirname(__DIR__) . '/Fixtures/concurrent-init.php';
        $processes = [];

        for ($index = 0; $index < 6; $index++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $fixture, $database],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
            self::assertSame(0, $exitCode);
        }

        $pdo = new PDO('sqlite:' . $database);
        self::assertSame(1, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame(
            3,
            (int)$pdo->query(
                "SELECT COUNT(*) FROM sqlite_master
                WHERE type = 'table' AND name IN ('cache_entries', 'cache_generations', 'items')"
            )->fetchColumn()
        );
    }
}

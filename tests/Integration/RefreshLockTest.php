<?php

namespace Alfred\Productive\Tests\Integration;

use Alfred\Productive\Cache\RefreshLock;
use PHPUnit\Framework\TestCase;

class RefreshLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alfred-productive-lock-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOnlyOneProcessCanRefreshTheSameCacheKey(): void
    {
        $first = new RefreshLock('services', $this->directory);
        $second = new RefreshLock('services', $this->directory);
        $different = new RefreshLock('deals', $this->directory);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
        self::assertTrue($different->acquire());

        $first->release();
        self::assertTrue($second->acquire());
    }

    public function testReleaseIsIdempotent(): void
    {
        $lock = new RefreshLock('services', $this->directory);

        self::assertTrue($lock->acquire());
        $lock->release();
        $lock->release();
        self::assertTrue($lock->acquire());
    }
}

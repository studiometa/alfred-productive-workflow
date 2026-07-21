<?php

namespace Alfred\Productive\Cache;

use RuntimeException;
use function Alfred\Productive\Functions\utils\get_root_dir;

class RefreshLock
{
    /** @var resource|null */
    private $handle;

    private string $path;

    public function __construct(string $cacheKey, ?string $directory = null)
    {
        $directory = $directory ?: get_root_dir() . '/cache/locks';

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create the cache lock directory: {$directory}");
        }

        $this->path = $directory . '/' . hash('sha256', $cacheKey) . '.lock';
    }

    public function acquire(): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }

        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Could not open the cache refresh lock: {$this->path}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}

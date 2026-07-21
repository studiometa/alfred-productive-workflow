<?php

use Alfred\Productive\Cache\RefreshLock;
use Alfred\Productive\Cache\SqliteStore;
use Alfred\Productive\Resources\Deals;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $database, $lockDirectory, $cacheKey] = $argv;
$store = new SqliteStore($database);
$lock = new RefreshLock($cacheKey, $lockDirectory);

if (!$lock->acquire()) {
    fwrite(STDERR, "Could not acquire fixture lock.\n");
    exit(1);
}

echo "locked\n";
fflush(STDOUT);
usleep(750000);

$generation = $store->beginRefresh($cacheKey, Deals::class, [], 100);
$store->stageItems($generation, [[
    'uid' => 'published-by-owner',
    'title' => 'Published by owner',
    'variables' => [],
]], 101);
$store->publishRefresh($cacheKey, $generation, 110, 60);
$lock->release();

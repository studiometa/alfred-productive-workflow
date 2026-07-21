<?php

use Alfred\Productive\Cache\SqliteStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$database = $argv[1] ?? null;
if (!is_string($database) || $database === '') {
    fwrite(STDERR, "Missing database path.\n");
    exit(1);
}

new SqliteStore($database);

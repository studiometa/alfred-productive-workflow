<?php

declare(strict_types=1);

namespace Alfred\Productive;

use function Alfred\Productive\Functions\main\main;
use function Alfred\Productive\Functions\utils\get_root_dir;

require dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('ignore_repeated_errors', true);
ini_set('display_errors', false);
ini_set('log_errors', true);
ini_set('error_log', get_root_dir() . '/logs/error.log');
ini_set('memory_limit', '1024M');

main($argv);

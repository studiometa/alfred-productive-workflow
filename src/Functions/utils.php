<?php

namespace Alfred\Productive\Functions\utils;

function array_get(array $arr, string $path)
{
    $parts = explode('.', $path);
    $current = $arr;

    foreach ($parts as $part) {
        $current = $current[$part] ?? null;

        if (is_null($current)) {
            break;
        }
    }

    return $current;
}

function logger(...$msg): void
{
    $log_path = get_root_dir() . '/logs/run.log';

    file_put_contents(
        $log_path,
        sprintf(
            '[%s] %s%s',
            date('Y-m-d H:i:s e'),
            trim(implode(' ', array_map(function ($item) {
                if (is_array($item) || is_object($item)) {
                    return json_encode($item);
                }
                return $item;
            }, $msg))),
            PHP_EOL
        ),
        FILE_APPEND | LOCK_EX
    );
}


function get_root_dir(): string
{
    return dirname(__DIR__, 2);
}

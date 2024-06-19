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

/**
 * Join a list of arguments into a single string with a `,` as separator.
 * @param  string $args
 * @return string
 */
function to_array_parameter(...$args): string
{
    return implode(',', $args);
}

/**
 * Get a date in the format YYYY-MM-DD from some time ago.
 * @param  string $time
 * @return string
 */
function time_ago(string $time = '-6 months'): string
{
    return date('Y-m-d', strtotime($time));
}

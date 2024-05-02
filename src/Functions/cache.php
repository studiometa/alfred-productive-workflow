<?php

namespace Alfred\Productive\Functions\cache;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use function Alfred\Productive\Functions\cli\get_cli_args;
use function Alfred\Productive\Functions\utils\get_root_dir;

function should_update_cache(): bool
{
    return in_array('--update-cache', get_cli_args());
}

function get_cache_dir(): string
{
    return get_root_dir() . '/cache';
}

function get_cache(): FilesystemAdapter
{
    return new FilesystemAdapter(
        namespace: 'productive',
        directory: get_cache_dir(),
    );
}

function generate_cache_key(string $resource_class, array $parameters = [])
{
    return md5($resource_class . json_encode($parameters));
}

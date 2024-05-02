<?php

namespace Alfred\Productive\Functions\env;

use Dotenv\Dotenv;
use function Alfred\Productive\Functions\utils\get_root_dir;

function env(string $key, ?string $default = null):string
{
    (Dotenv::createImmutable(get_root_dir()))->safeLoad();
    /** @var array<string, string> */
    $server = $_SERVER;
    return (string)($server[$key] ?? $default);
}

function get_update_interval(): int
{
    return (int)(env('UPDATE_INTERVAL') ?: 60);
}

function get_org_id(): string
{
    return env('PRODUCTIVE_ORG_ID');
}

function get_person_id(): string
{
    return env('PRODUCTIVE_PERSON_ID');
}

function get_auth_token(): string
{
    return env('PRODUCTIVE_AUTH_TOKEN');
}

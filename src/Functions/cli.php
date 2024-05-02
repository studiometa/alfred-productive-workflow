<?php

namespace Alfred\Productive\Functions\cli;

function get_cli_args():array
{
    global $argv;
    return $argv;
}

function get_cli_arg(string $name): null|string
{
    $arg_name = "--{$name}=";
    $arg = collect(get_cli_args())
        ->first(fn ($value) => str_starts_with($value, $arg_name));

    return is_string($arg) ? str_replace($arg_name, '', $arg) : $arg;
}

function cli_company_id():null|string
{
    return get_cli_arg('company');
}

function cli_service_id():null|string
{
    return get_cli_arg('service');
}

function cli_task_id():null|string
{
    return get_cli_arg('task');
}

function cli_no_ended_deal(): bool
{
    return in_array('--no-ended-deal', get_cli_args());
}

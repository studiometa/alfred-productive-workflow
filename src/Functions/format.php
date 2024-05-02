<?php

namespace Alfred\Productive\Functions\format;

function format_minutes(?int $minutes): string
{
    if (is_null($minutes)) {
        return '...';
    }

    /** @var int */
    $time = mktime(0, $minutes);

    $hours = date('G', $time);
    $min = date('i', $time);

    $is_less_than_one_hour = (int)$hours < 1;
    $is_round_hour = $min === '00';

    if ($is_round_hour) {
        return "{$hours} h";
    }

    if ($is_less_than_one_hour) {
        return "{$min} min";
    }

    return "{$hours} h {$min} min";
}

function format_cents(?int $cents): string
{
    if (is_null($cents)) {
        return '...';
    }

    return sprintf('%s €', $cents / 100);
}

function format_name(array $person):string
{
    /** @var null|string */
    $first_name = $person['attributes']['first_name'];
    /** @var null|string */
    $last_name = $person['attributes']['last_name'];
    $last_name_initial = strtoupper(substr($last_name, 0, 1));

    if ($first_name && $last_name) {
        return "{$first_name} {$last_name_initial}.";
    }

    if (is_null($first_name) && $last_name) {
        return $last_name;
    }

    if ($first_name && is_null($last_name)) {
        return $first_name;
    }

    return "";
}

function format_subtitle(array $items):string
{
    return implode(
        ' → ',
        array_filter(
            $items,
            fn ($value) => !is_null($value),
        )
    );
}

function format_match(array $item): string
{
    return str_replace(
        ['(', ')', '/'],
        [' ', ' ', ' '],
        implode(' ', [$item['uid'], $item['title'], $item['subtitle']])
    );
}

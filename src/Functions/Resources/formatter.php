<?php

namespace Alfred\Productive\Functions\Resources\formatter;

use Exception;
use function Alfred\Productive\Functions\format\format_subtitle;
use function Alfred\Productive\Functions\format\format_match;
use function Alfred\Productive\Functions\format\format_name;
use function Alfred\Productive\Functions\format\format_minutes;
use function Alfred\Productive\Functions\format\format_cents;
use function Alfred\Productive\Functions\env\get_org_id;
use function Alfred\Productive\Functions\utils\logger;
use function Alfred\Productive\Functions\utils\array_get;

function tasks_formatter(array $task): array
{
    $project = $task['relationships']['project'];
    $company = $project['relationships']['company'];
    $assignee = $task['relationships']['assignee'];
    $status = $task['relationships']['workflow_status'];

    $task_key = sprintf(
        '%s-%s-%s',
        $company['attributes']['company_code'],
        $project['attributes']['project_number'],
        $task['attributes']['task_number']
    );

    $item = [
        'title'    => $task['attributes']['title'],
        'uid'      => $task['id'],
        'arg'      => sprintf('https://app.productive.io/%s/task/%s', get_org_id(), $task['id']),
        'subtitle' => format_subtitle([
            $task_key,
            $company['attributes']['name'],
            $project['attributes']['name'],
            $status['attributes']['name'],
            isset($assignee['attributes']) ? format_name($assignee) : null,
            sprintf(
                '%s / %s',
                format_minutes($task['attributes']['worked_time']),
                format_minutes($task['attributes']['initial_estimate'])
            ),
        ]),
        'variables' => [
            'task_url'      => sprintf('https://app.productive.io/%s/task/%s', get_org_id(), $task['id']),
            'task_name'     => $task['attributes']['title'],
            'task_id'       => $task['id'],
            'task_key'      => $task_key,
            'project_id'    => $project['id'],
            'company_id'    => $company['id'],
            'assignee_id'   => $assignee ? $assignee['id'] ?? null : null,
            'status_id'     => $status['id'],
            'relationships' => $task['relationships'],
            'attributes'    => $task['attributes'],
        ],
    ];

    $item['match'] = format_match($item);

    return $item;
}

function projects_formatter(array $project): array
{
    $company = $project['relationships']['company'];

    $item = [
        'title' => $project['attributes']['name'],
        'subtitle' => format_subtitle([
            $company['attributes']['company_code'],
            $company['attributes']['name'],
        ]),
        'arg'   => sprintf('https://app.productive.io/%s/projects/%s', get_org_id(), $project['id']),
        'uid'   => $project['id'],
        'variables' => [
            'project_id' => $project['id'],
            'company_id' => $company['id'],
            'relationships' => $project['relationships'],
            'attributes' => $project['attributes'],
        ],
    ];

    $item['match'] = format_match($item);

    return $item;
}

function deals_formatter(array $deal):array
{
    $company = $deal['relationships']['company'];
    $responsible = $deal['relationships']['responsible'];
    $deal_status = $deal['relationships']['deal_status'];

    $status = [
        1 => 'Ouvert',
        2 => 'Gagné',
        3 => 'Perdu',
    ];

    $item = [
        'title'     => $deal['attributes']['name'],
        'subtitle'  => format_subtitle([
            $company['attributes']['company_code'],
            $company['attributes']['name'],
            isset($responsible['attributes']) ? format_name($responsible) : null,
            $status[$deal['attributes']['sales_status_id'] ?? 3],
            isset($deal_status['attributes']) ? $deal_status['attributes']['name'] : null,
        ]),
        'arg'       => sprintf('https://app.productive.io/%s/d/deal/%s', get_org_id(), $deal['id']),
        'uid'       => $deal['id'],
        'variables' => [
            'deal_id'       => $deal['id'],
            'company_id'    => $company['id'],
            'relationships' => $deal['relationships'],
            'attributes'    => $deal['attributes'],
        ],
    ];

    $item['match'] = format_match($item);

    return $item;
}

function companies_formatter(array $company):array
{
    $item = [
        'title'     => $company['attributes']['name'],
        'subtitle'  => format_subtitle([
            $company['attributes']['company_code'],
            $company['attributes']['name'],
        ]),
        'arg'       => sprintf('https://app.productive.io/%s/companies/%s', get_org_id(), $company['id']),
        'uid'       => $company['id'],
        'variables' => [
            'attributes' => $company['attributes'],
        ],
    ];

    $item['match'] = format_match($item);

    return $item;
}

function services_formatter(array $service):array
{
    $deal = $service['relationships']['deal'];
    $company = $deal['relationships']['company'];

    $deal_status = is_null(array_get($deal, 'attributes.closed_at')) ? 'Open' : 'Closed';
    $deal_name = $deal['attributes']['name'];

    if (!empty($deal['attributes']['suffix'])) {
        $deal_name = sprintf(
            '%s (%s)',
            $deal_name,
            $deal['attributes']['suffix'],
        );
    }

    $item = [
        'title'     => $service['attributes']['name'],
        'subtitle'  => format_subtitle([
            $company['attributes']['company_code'],
            $company['attributes']['name'],
            $deal_name,
            $deal_status,
            sprintf(
                '%s / %s',
                format_minutes($service['attributes']['worked_time']),
                format_minutes($service['attributes']['budgeted_time'])
            ),
            sprintf(
                '%s / %s',
                format_cents($service['attributes']['budget_used']),
                format_cents($service['attributes']['budget_total'])
            ),
        ]),
        'arg'       => sprintf('https://app.productive.io/%s/d/deal/%s/services', get_org_id(), $deal['id']),
        'uid'       => $service['id'],
        'variables' => [
            'service_name'  => $service['attributes']['name'],
            'company_name'  => $company['attributes']['name'],
            'service_id'    => $service['id'],
            'deal_id'       => $deal['id'],
            'company_id'    => $company['id'],
            'relationships' => $service['relationships'],
            'attributes'    => $service['attributes'],
        ],
    ];

    $item['match'] = format_match($item);

    return $item;
}

function people_formatter(array $person):array
{
    $company = $person['relationships']['company'];

    $item = [
        'title'     => trim(sprintf(
            '%s %s',
            $person['attributes']['first_name'],
            $person['attributes']['last_name']
        )),
        'subtitle'  => format_subtitle([
            isset($company['attributes']) ? $company['attributes']['name'] : null,
            $person['attributes']['title'],
            $person['attributes']['email'],
        ]),
        'arg'       => sprintf('https://app.productive.io/%s/people/%s', get_org_id(), $person['id']),
        'uid'       => $person['id'],
        'variables' => [
            'relationships' => $person['relationships'],
            'attributes' => $person['attributes'],
        ],
    ];

    $item['match'] = format_match($item);

    return $item;
}

function validate_formatter(string $cmd):callable
{
    logger('validate_formatter', $cmd);
    $formatter = __NAMESPACE__ . "\\{$cmd}_formatter";

    if (!function_exists($formatter)) {
        throw new Exception("The '{$formatter}' function does not exist.");
    }

    return $formatter;
}

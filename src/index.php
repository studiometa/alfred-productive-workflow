<?php

declare(strict_types=1);

namespace Alfred\Productive;

use Exception;
use ReflectionClass;
use Dotenv\Dotenv;
use Brandlabs\Productiveio\ApiClient as Client;
use Brandlabs\Productiveio\BaseResource;
use Brandlabs\Productiveio\Resources\Projects;
use Brandlabs\Productiveio\Resources\Tasks;
use Brandlabs\Productiveio\Resources\Companies;
use Alfred\Productive\Resources\Deals;
use Alfred\Productive\Resources\Services;
use Brandlabs\Productiveio\Resources\People;
use Brandlabs\Productiveio\Resources\TimeEntries;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

define('ROOT_DIR', dirname(__DIR__));

require ROOT_DIR . '/vendor/autoload.php';

ini_set('memory_limit', '1024M');

function env(string $key, ?string $default = null):string
{
    (Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
    /** @var array<string, string> */
    $server = $_SERVER;
    return (string)($server[$key] ?? $default);
}

function get_cache_dir(): string
{
    if (env('alfred_workflow_cache')) {
        return env('alfred_workflow_cache');
    }

    $cache_dir = ROOT_DIR . '/cache';
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir);
    }

    return $cache_dir;
}

function get_tmp_dir(): string
{
    return sys_get_temp_dir();
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

function get_client(): Client
{
    $client = new Client(
        authToken: get_auth_token(),
        organisationId: (int)get_org_id()
    );

    return $client;
}

function logger(?string $msg = ''): void
{
    echo $msg ?? '' . PHP_EOL;
}

function get_cache(int $ttl = -1): ArrayAdapter|FilesystemAdapter
{
    if (env('APP_CACHE') === 'false') {
        return new ArrayAdapter(
            defaultLifetime: $ttl,
        );
    }

    return new FilesystemAdapter(
        namespace: 'productive',
        defaultLifetime: $ttl,
        directory: get_cache_dir(),
    );
}

function merge_relationships(array $data, array $included): array
{
    $included_collection = collect($included);
    $merged = [];
    foreach ($data as $row) {
        $new_row = $row;
        $new_row['included'] = $included;

        foreach ($row['relationships'] as $key => $data) {
            $type = $data['data']['type'] ?? null;
            $id = $data['data']['id'] ?? null;

            if (is_null($type) || is_null($id)) {
                continue;
            }

            $resolved_relationship = $included_collection->where('type', $type)->where('id', $id)->first();

            // Resolve companies relationships for projects
            if ($type === 'projects' && isset($resolved_relationship['relationships']['company'])) {
                $company = $included_collection
                    ->where('type', 'companies')
                    ->where('id', $resolved_relationship['relationships']['company']['data']['id'])
                    ->first();

                if ($company) {
                    $resolved_relationship['relationships']['company'] = $company;
                }
            }

            if (!is_null($resolved_relationship)) {
                $new_row['relationships'][$key] = $resolved_relationship;
            }
        }

        $merged[] = $new_row;
    }

    return $merged;
}

function validate_resource_class(string $resource_class): void
{
    if (!class_exists($resource_class)) {
        throw new Exception("The '{$resource_class}' class does not exist.");
    }

    if (!(new ReflectionClass($resource_class))->isSubclassOf(BaseResource::class)) {
        throw new Exception("The '{$resource_class}' class is not a subclass of '".BaseResource::class."'.");
    }
}

function create_time_entry(
    int $service_id,
    int $task_id,
    int $duration_in_minutes
) {
    $resource = new TimeEntries(get_client());
    $resource->create([
        'date' => date('Y-m-d'),
        'service_id' => $service_id,
        'task_id' => $task_id,
        'person_id' => get_person_id(),
        'time' => $duration_in_minutes,
    ]);
}

/**
 * @todo get paginated content in the background and update the cache
 */
function get_all_by_resource(string $resource_class, array $parameters = []):array
{
    validate_resource_class($resource_class);

    $client = get_client();
    /** @var Tasks|Projects */
    $resource = new $resource_class($client);

    $current_page = 1;
    $page_size = 100;
    $response = $resource->getList(['page[size]' => $page_size, 'page[number]' => $current_page] + $parameters);
    $data = $response['data'];
    $included = $response['included'];

    while ($current_page < $response['meta']['total_pages']) {
        $current_page += 1;
        $response = $resource->getList(['page[size]' => (string)$page_size, 'page[number]' => (string)$current_page]);
        $data = array_merge($data, $response['data']);
        $included = array_merge($included, $response['included']);
    }

    return merge_relationships($data, $included);
}

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
    return implode(' ', [$item['title'], $item['subtitle']]);
}

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
        'arg'       => sprintf('https://app.productive.io/%s/deals/%s', get_org_id(), $deal['id']),
        'uid'       => $deal['id'],
        'variables' => [
            'relationships' => $deal['relationships'],
            'attributes' => $deal['attributes'],
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
            'relationships' => $company['relationships'],
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

    $item = [
        'title'     => $service['attributes']['name'],
        'subtitle'  => format_subtitle([
            $company['attributes']['company_code'],
            $company['attributes']['name'],
            $deal['attributes']['name'],
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
        'arg'       => sprintf('https://app.productive.io/%s/companies/%s', get_org_id(), $service['id']),
        'uid'       => $service['id'],
        'variables' => [
            'relationships' => $service['relationships'],
            'attributes' => $service['attributes'],
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
    $formatter = __NAMESPACE__ . "\\{$cmd}_formatter";

    if (!function_exists($formatter)) {
        throw new Exception("The '{$formatter}' function does not exist.");
    }

    return fn (...$args) => $formatter(...$args);
}

function cmd(string $cmd, string $resource_class, array $parameters = [], ?bool $clear_cache = false):void
{
    validate_resource_class($resource_class);

    $cache = get_cache();
    $cache_key = $cmd;

    if (!$clear_cache) {
        update_in_background($cmd);
    }

    $resource_formatter = validate_formatter($cmd);
    $items = $cache->get($cache_key, function () use ($resource_formatter, $parameters, $resource_class) {
        $resources = get_all_by_resource($resource_class, $parameters);
        $items = [];

        foreach ($resources as $resource) {
            $items[] = $resource_formatter($resource);
        }

        return ['rerun' => 5, 'items' => $items];
    });

    echo json_encode($items);
}

/**
 * Execute a command in a background process and without cache.
 */
function update_in_background(string $cmd):void
{
    $php_exec = env('_');
    $script = ROOT_DIR . '/src/index.php';
    $logs = get_tmp_dir() . '/alfred-productive-workflow-background-update.log';
    $pid = get_tmp_dir() . '/alfred-productive-workflow-background-pid.txt';

    exec(sprintf("%s > %s 2>&1 & echo $! >> %s", "{$php_exec} {$script} {$cmd} --clear-cache", $logs, $pid));
}

/**
 * Main entrypoint.
 * @param string[] $args
 */
function main(array $args):void
{
    $command = $args[1] ?? 'tasks';
    $clear_cache = in_array('--clear-cache', $args);

    $resources_map = [
        'companies' => [Companies::class, ['filter[status]' => 1]],
        'deals'     => [Deals::class, [
            'filter[sales_status_id]' => '1,2,3',
        ]],
        'people'    => [People::class, ['filter[status]' => 1]],
        'projects'  => [Projects::class, []],
        'services'  => [Services::class, []],
        'tasks'     => [Tasks::class, ['sort' => '-updated_at']],
    ];

    cmd(
        $command,
        $resources_map[$command][0],
        $resources_map[$command][1],
        $clear_cache
    );
}

main($argv);

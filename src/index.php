<?php

namespace Alfred\Productive;

use Exception;
use ReflectionClass;
use Dotenv\Dotenv;
use Brandlabs\Productiveio\ApiClient as Client;
use Brandlabs\Productiveio\BaseResource;
use Brandlabs\Productiveio\Resources\Projects;
use Brandlabs\Productiveio\Resources\Tasks;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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

function get_cache(int $ttl = 0): FilesystemAdapter
{
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
        return "{$hours}h";
    }

    if ($is_less_than_one_hour) {
        return "{$min}min";
    }

    return "{$hours}h{$min}min";
}

function format_name(array $person):string
{
    $first_name = $person['attributes']['first_name'];
    $last_name = $person['attributes']['last_name'];
    $last_name_initial = strtoupper(substr($last_name, 0, 1));

    return "{$first_name} {$last_name_initial}.";
}

function tasks_formatter(array $task): array
{
    $project = $task['relationships']['project'];
    $company = $project['relationships']['company'];
    $assignee = $task['relationships']['assignee'];
    $status = $task['relationships']['workflow_status'];

    $item = [
        'title'    => $task['attributes']['title'],
        'uid'      => $task['id'],
        'arg'      => sprintf('https://app.productive.io/%s/task/%s', get_org_id(), $task['id']),
        'subtitle' => implode(
            ' → ',
            array_filter(
                [
                    $company['attributes']['name'],
                    $project['attributes']['name'],
                    $status['attributes']['name'],
                    isset($assignee['attributes']) ? format_name($assignee) : null,
                    sprintf(
                        '%s / %s',
                        format_minutes($task['attributes']['worked_time']),
                        format_minutes($task['attributes']['initial_estimate'])
                    ),
                ],
                fn ($value) => !is_null($value),
            )
        ),
    ];

    $item['match'] = implode(' ', [$item['title'], $item['subtitle']]);

    return $item;
}

function projects_formatter(array $project): array
{
    $company = $project['relationships']['company'];

    return [
        'title' => $project['attributes']['name'] . ' — ' . $company['attributes']['name'],
        'arg'   => sprintf('https://app.productive.io/%s/projects/%s', get_org_id(), $project['id']),
        'uid'   => $project['id'],
    ];
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

    switch ($command) {
        case 'projects':
            cmd($command, Projects::class, [], $clear_cache);
            break;
        case 'tasks':
            cmd($command, Tasks::class, ['sort' => '-updated_at'], $clear_cache);
            break;
    }
}

main($argv);

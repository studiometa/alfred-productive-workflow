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
use Symfony\Component\Cache\CacheItem;

define('ROOT_DIR', dirname(__DIR__));

require ROOT_DIR . '/vendor/autoload.php';

ini_set('memory_limit', '1024M');

function get_cli_args():array {
    global $argv;
    return $argv;
}

function should_update_cache(): bool {
    return in_array('--update-cache', get_cli_args());
}

function env(string $key, ?string $default = null):string
{
    (Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
    /** @var array<string, string> */
    $server = $_SERVER;
    return (string)($server[$key] ?? $default);
}

function get_cache_dir(): string
{
    return ROOT_DIR . '/cache';
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

function logger(...$msg): void
{
    // Do not log anything when outputting data for Alfred
    if (env('alfred_workflow_uid') && !should_update_cache()) {
        return;
    }

    echo trim(implode(' ', $msg)) . PHP_EOL;
}

function get_cache(): FilesystemAdapter
{
    return new FilesystemAdapter(
        namespace: 'productive',
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

            // Resolve companies relationships for projects and deals
            if (in_array($type, ['projects','deals']) && isset($resolved_relationship['relationships']['company'])) {
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

function generate_cache_key(string $resource_class, array $parameters = [])
{
    return md5($resource_class . json_encode($parameters));
}

function fetch_all_by_resource(string $resource_class, callable $resource_formatter, array $parameters = [])
{
    logger('fetch_all_by_resource', $resource_class);

    $cache = get_cache();
    $last_update_item = $cache->getItem(md5('last_update_' . $resource_class));
    if ($last_update_item->isHit()) {
        logger('last fetch happened less than 1 minute ago, not fetching.');
        return;
    } else {
        $last_update_item->expiresAfter(60);
        $cache->save($last_update_item);
    }

    validate_resource_class($resource_class);

    $client = get_client();
    /** @var Projects|Tasks|Companies|Deals|Services|People|TimeEntries */
    $resource = new $resource_class($client);

    $cache_key = generate_cache_key($resource_class, $parameters);
    $cache_item = $cache->getItem($cache_key);

    logger('fetch_all_by_resource', $cache_key);

    $current_page = 1;
    $page_size = 200;
    $parameters = ['page[size]' => $page_size, 'page[number]' => $current_page] + $parameters;

    $response = $resource->getList($parameters);
    $data = $response['data'];
    $included = $response['included'];

    $items = array_map($resource_formatter, merge_relationships($data, $included));
    $cache_item->set($items);
    $cache->save($cache_item);

    while ($current_page < $response['meta']['total_pages']) {
        $current_page += 1;
        logger('fetch_all_by_resource', $current_page, $page_size, count($cache_item->get()));
        $response = $resource->getList([
            'page[size]' => $page_size,
            'page[number]' => $current_page
        ]);

        $data = array_merge($data, $response['data']);
        $included = array_merge($included, $response['included']);
        $items = array_map($resource_formatter, merge_relationships($data, $included));
        $cache_item->set($items);
        $cache->save($cache_item);
    }
}

function get_all_by_resource_from_cache(string $resource_class, array $parameters = []):array
{
    $cache = get_cache();
    $cache_key = generate_cache_key($resource_class, $parameters);
    $cache_item = $cache->getItem($cache_key);

    logger('get_all_by_resource_from_cache', $cache_key, $cache_item->isHit() ? 'hit' : 'miss');

    while (!$cache_item->isHit()) {
        sleep(1);
        $cache_item = $cache->getItem($cache_key);
        logger('nothing to display');
    }

    $items = $cache_item->get();
    return $items;
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

function cmd(string $cmd, string $resource_class, array $parameters = []):void
{
    validate_resource_class($resource_class);
    $resource_formatter = validate_formatter($cmd);

    if (should_update_cache()) {
        fetch_all_by_resource($resource_class, $resource_formatter, $parameters);
    } else {
        $items = get_all_by_resource_from_cache($resource_class, $parameters);
        die(json_encode(['items' => $items], JSON_PRETTY_PRINT));
    }
}

/**
 * Main entrypoint.
 * @param string[] $args
 */
function main(array $args):void
{
    $command = $args[1] ?? 'tasks';

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

    logger('main', should_update_cache());

    cmd(
        $command,
        $resources_map[$command][0],
        $resources_map[$command][1]
    );
}

main($argv);

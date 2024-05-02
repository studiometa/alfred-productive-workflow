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
use Alfred\Productive\Resources\Timers;
use Brandlabs\Productiveio\Exceptions\ProductiveioRequestException;
use Brandlabs\Productiveio\Resources\People;
use Brandlabs\Productiveio\Resources\TimeEntries;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

define('ROOT_DIR', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('ignore_repeated_errors', true);
ini_set('display_errors', false);
ini_set('log_errors', true);
ini_set('error_log', ROOT_DIR . '/logs/error.log');

require ROOT_DIR . '/vendor/autoload.php';

ini_set('memory_limit', '1024M');

function get_cli_args():array
{
    global $argv;
    return $argv;
}

function should_update_cache(): bool
{
    return in_array('--update-cache', get_cli_args());
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

function env(string $key, ?string $default = null):string
{
    (Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
    /** @var array<string, string> */
    $server = $_SERVER;
    return (string)($server[$key] ?? $default);
}

function array_get(array $arr, string $path) {
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

function get_update_interval(): int
{
    return (int)(env('UPDATE_INTERVAL') ?: 60);
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
    $log_path = ROOT_DIR . '/logs/run.log';

    file_put_contents(
        $log_path,
        sprintf(
            '[%s] %s%s',
            date('Y-m-d H:i:s e'),
            trim(implode(' ', $msg)),
            PHP_EOL
        ),
        FILE_APPEND | LOCK_EX
    );
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

function generate_cache_key(string $resource_class, array $parameters = [])
{
    return md5($resource_class . json_encode($parameters));
}

function get_resource_name(string $resource_class)
{
    return basename(str_replace('\\', '/', $resource_class));
}

function fetch_all_by_resource(string $resource_class, callable $resource_formatter, array $parameters = [])
{
    $resource_name = get_resource_name($resource_class);
    $logger = fn (...$args) => logger("[{$resource_name}]", ...$args);
    $logger('fetch_all_by_resource', $resource_formatter, json_encode($parameters));

    $cache = get_cache();
    $last_update_item = $cache->getItem(md5('last_update_' . $resource_class));
    if ($last_update_item->isHit()) {
        $logger('last fetch happened less than 1 minute ago, not fetching.');
        return;
    } else {
        $last_update_item->expiresAfter(get_update_interval());
        $cache->save($last_update_item);
    }

    validate_resource_class($resource_class);

    $client = get_client();
    /** @var Projects|Tasks|Companies|Deals|Services|People|TimeEntries */
    $resource = new $resource_class($client);

    $cache_key = generate_cache_key($resource_class, $parameters);
    $cache_item = $cache->getItem($cache_key);
    $cached_items = collect($cache_item->get());

    $logger('fetch_all_by_resource', $cache_key);

    $current_page = 1;
    $page_size = 200;
    $parameters = ['page[size]' => $page_size, 'page[number]' => $current_page] + $parameters;

    $response = $resource->getList($parameters);
    $data = $response['data'];
    $included = $response['included'];

    $items = array_values(
        $cached_items->concat(
            array_map(
                $resource_formatter,
                merge_relationships($data, $included)
            )
        )->reverse()->unique('uid')->all()
    );

    $cache_item->set($items);
    $cache->save($cache_item);

    while ($current_page < $response['meta']['total_pages']) {
        $current_page += 1;
        $logger('fetch_all_by_resource', $current_page, $page_size, count($cache_item->get()));
        $response = $resource->getList([
            'page[size]' => $page_size,
            'page[number]' => $current_page
        ]);

        $data = array_merge($data, $response['data']);
        $included = array_merge($included, $response['included']);
        $cached_items = collect($cache_item->get());
        $items = array_values(
            $cached_items->concat(
                array_map(
                    $resource_formatter,
                    merge_relationships($data, $included)
                )
            )->reverse()->unique('uid')->all()
        );
        $cache_item->set($items);
        $cache->save($cache_item);
    }
}

function get_all_by_resource_from_cache(string $resource_class, array $parameters = []):array
{
    $resource_name = get_resource_name($resource_class);
    $logger = fn (...$args) => logger("[{$resource_name}]", ...$args);
    $logger('get_all_by_resource_from_cache', json_encode($parameters));


    $cache = get_cache();
    $cache_key = generate_cache_key($resource_class, $parameters);
    $cache_item = $cache->getItem($cache_key);

    $logger('get_all_by_resource_from_cache', $cache_key, $cache_item->isHit() ? 'hit' : 'miss');

    while (!$cache_item->isHit()) {
        sleep(1);
        $cache_item = $cache->getItem($cache_key);
        $logger('get_all_by_resource_from_cache', 'nothing to display');
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
    return str_replace(
        ['(', ')', '/'],
        [' ', ' ', ' '],
        implode(' ', [$item['uid'], $item['title'], $item['subtitle']])
    );
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

    $deal_status = is_null(array_get($deal, 'attributes.closed_at')) ? 'Open' : 'Closed';

    $item = [
        'title'     => $service['attributes']['name'],
        'subtitle'  => format_subtitle([
            $company['attributes']['company_code'],
            $company['attributes']['name'],
            $deal_status,
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
    $formatter = __NAMESPACE__ . "\\{$cmd}_formatter";

    if (!function_exists($formatter)) {
        throw new Exception("The '{$formatter}' function does not exist.");
    }

    return $formatter;
}

function start_timer(
    string $service_id,
    ?string $task_id,
) {
    logger('start_timer', "service: {$service_id}", "task: {$task_id}");
    $client = get_client();
    $time_entries = new TimeEntries($client);
    $params = [
        'data' => [
            'type' => 'time_entries',
            'attributes' => [
                'date' => date('Y-m-d'),
            ],
            'relationships' => [
                'person' => [
                    'data' => [
                        'type' => 'people',
                        'id' => get_person_id()
                    ]
                ],
                'service' => [
                    'data' => [
                        'type' => 'services',
                        'id' => $service_id,
                    ],
                ],
            ],
        ],
    ];

    if (!is_null($task_id)) {
        $params['data']['relationships']['task'] = [
            'data' => [
                'type' => 'tasks',
                'id' => $task_id,
            ]
        ];
    }

    logger('start_timer', 'create_time_entry', json_encode($params));
    try {
        $response = $time_entries->create($params);
    } catch (ProductiveioRequestException $error) {
        logger('start_timer', 'failed to create time entry', $error->getMessage());
        error_log($error->getMessage());
        die;
    }

    $timers = new Timers($client);
    $params = [
        'data' => [
            'type' => 'timers',
            'relationships' => [
                'time_entry' => [
                    'data' => [
                        'type' => 'time_entries',
                        'id' => array_get($response, 'data.id'),
                    ],
                ],
            ],
        ],
    ];
    logger('start_timer', 'create_timer', json_encode($params));
    try {
        $timers->create($params);
    } catch (ProductiveioRequestException $error) {
        logger('start_timer', 'failed to create timer', $error->getMessage());
        error_log($error->getMessage());
    }
}

function cmd(string $cmd, string $resource_class, array $parameters = []):void
{
    validate_resource_class($resource_class);

    if (should_update_cache()) {
        fetch_all_by_resource($resource_class, validate_formatter($cmd), $parameters);
    } else {
        $items = collect(get_all_by_resource_from_cache($resource_class, $parameters));

        if ($company_id = cli_company_id()) {
            $items = $items->filter(function($value) use ($company_id) {
                $relationships = $value['variables']['relationships'];
                return
                    array_get($relationships, 'company.id') === $company_id ||
                    array_get($relationships, 'deal.relationships.company.id') === $company_id;
            });
        }

        if (cli_no_ended_deal()) {
            $items = $items->filter(function($value) {
                $attributes = array_get($value, 'variables.relationships.deal.attributes');
                return !is_null($attributes) && is_null(array_get($attributes, 'closed_at'));
            });
        }

        die(json_encode(['items' => array_values($items->all())]));
    }
}

/**
 * Main entrypoint.
 * @param string[] $args
 */
function main(array $args):void
{
    $command = $args[1] ?? 'tasks';

    if ($command === 'start-timer') {
        $service_id = cli_service_id();
        $task_id = cli_task_id();

        if (!is_null($service_id)) {
            start_timer(
                service_id: $service_id,
                task_id: is_string($task_id) ? $task_id : null,
            );
        }

        return;
    }

    $resources_map = [
        'companies' => [Companies::class, ['filter[status]' => 1]],
        'deals'     => [Deals::class, [
            'filter[sales_status_id]' => '1,2,3',
        ]],
        'people'    => [People::class, ['filter[status]' => 1]],
        'projects'  => [Projects::class, []],
        'services'  => [Services::class, [
            'filter[budget_status]' => '1',
            'filter[time_tracking_enabled]' => 'true',
        ]],
        'tasks'     => [Tasks::class, ['sort' => '-updated_at']],
    ];

    logger('main', $command, should_update_cache() ? '--update-cache' : '');

    cmd(
        $command,
        $resources_map[$command][0],
        $resources_map[$command][1]
    );
}

main($argv);

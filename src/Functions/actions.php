<?php

namespace Alfred\Productive\Functions\actions;

use Brandlabs\Productiveio\Exceptions\ProductiveioRequestException;
use Brandlabs\Productiveio\Resources\TimeEntries;
use Alfred\Productive\Resources\Timers;

use function Alfred\Productive\Functions\Resources\fetch\fetch_all_by_resource;
use function Alfred\Productive\Functions\Resources\fetch\get_all_by_resource_from_cache;
use function Alfred\Productive\Functions\Resources\formatter\validate_formatter;
use function Alfred\Productive\Functions\Resources\utils\validate_resource_class;
use function Alfred\Productive\Functions\cache\should_update_cache;
use function Alfred\Productive\Functions\cli\cli_company_id;
use function Alfred\Productive\Functions\cli\cli_no_ended_deal;
use function Alfred\Productive\Functions\client\get_client;
use function Alfred\Productive\Functions\env\get_person_id;
use function Alfred\Productive\Functions\utils\array_get;
use function Alfred\Productive\Functions\utils\logger;

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
    logger('cmd', $cmd, $resource_class, $parameters);
    validate_resource_class($resource_class);

    if (should_update_cache()) {
        fetch_all_by_resource($resource_class, validate_formatter($cmd), $parameters);
    } else {
        $items = collect(get_all_by_resource_from_cache($resource_class, $parameters));

        if ($company_id = cli_company_id()) {
            $items = $items->filter(function ($value) use ($company_id) {
                $relationships = $value['variables']['relationships'];
                return
                    array_get($relationships, 'company.id') === $company_id ||
                    array_get($relationships, 'deal.relationships.company.id') === $company_id;
            });
        }

        if (cli_no_ended_deal()) {
            $items = $items->filter(function ($value) {
                $attributes = array_get($value, 'variables.relationships.deal.attributes');
                return !is_null($attributes) && is_null(array_get($attributes, 'closed_at'));
            });
        }

        die(json_encode(['items' => array_values($items->all())]));
    }
}

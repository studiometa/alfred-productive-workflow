<?php

namespace Alfred\Productive\Functions\Resources\fetch;

use Alfred\Productive\Resources\Deals;
use Alfred\Productive\Resources\Services;
use Brandlabs\Productiveio\Resources\Companies;
use Brandlabs\Productiveio\Resources\People;
use Brandlabs\Productiveio\Resources\Projects;
use Brandlabs\Productiveio\Resources\Tasks;
use Brandlabs\Productiveio\Resources\TimeEntries;
use function Alfred\Productive\Functions\Resources\utils\get_resource_name;
use function Alfred\Productive\Functions\Resources\utils\validate_resource_class;
use function Alfred\Productive\Functions\Resources\utils\merge_relationships;
use function Alfred\Productive\Functions\cache\generate_cache_key;
use function Alfred\Productive\Functions\cache\get_cache;
use function Alfred\Productive\Functions\client\get_client;
use function Alfred\Productive\Functions\env\get_update_interval;
use function Alfred\Productive\Functions\utils\logger;

function fetch_all_by_resource(string $resource_class, callable $resource_formatter, array $parameters = [])
{
    logger('fetch_all_by_resource', $resource_class, $resource_formatter, $parameters);
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

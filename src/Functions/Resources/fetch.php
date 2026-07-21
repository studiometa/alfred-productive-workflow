<?php

namespace Alfred\Productive\Functions\Resources\fetch;

use Alfred\Productive\Cache\RefreshLock;
use Alfred\Productive\Cache\SqliteStore;
use Alfred\Productive\Resources\Deals;
use Alfred\Productive\Resources\Services;
use Brandlabs\Productiveio\Resources\Companies;
use Brandlabs\Productiveio\Resources\People;
use Brandlabs\Productiveio\Resources\Projects;
use Brandlabs\Productiveio\Resources\Tasks;
use Brandlabs\Productiveio\Resources\TimeEntries;
use RuntimeException;
use Throwable;
use function Alfred\Productive\Functions\Resources\utils\get_resource_name;
use function Alfred\Productive\Functions\Resources\utils\validate_resource_class;
use function Alfred\Productive\Functions\Resources\utils\merge_relationships;
use function Alfred\Productive\Functions\cache\generate_cache_key;
use function Alfred\Productive\Functions\client\get_client;
use function Alfred\Productive\Functions\env\get_update_interval;
use function Alfred\Productive\Functions\utils\logger;

/**
 * @param array<string, mixed> $parameters
 * @param null|callable(array<string, mixed>): array<string, mixed> $pageFetcher
 * @param null|callable(): int $clock
 * @return array<int, array<string, mixed>>
 */
function fetch_all_by_resource(
    string $resource_class,
    callable $resource_formatter,
    array $parameters = [],
    ?SqliteStore $store = null,
    ?callable $pageFetcher = null,
    ?callable $clock = null,
    ?RefreshLock $lock = null,
    float $coldCacheWaitSeconds = 30.0
): array {
    logger('fetch_all_by_resource', $resource_class, $resource_formatter, $parameters);
    $resource_name = get_resource_name($resource_class);
    $logger = fn (...$args) => logger("[{$resource_name}]", ...$args);
    $logger('fetch_all_by_resource', $resource_formatter, json_encode($parameters));

    validate_resource_class($resource_class);

    $store = $store ?: new SqliteStore();
    $clock = $clock ?: fn (): int => time();
    $cache_key = generate_cache_key($resource_class, $parameters);
    $update_interval = get_update_interval();

    if ($store->isFresh($cache_key, $clock())) {
        $logger("last fetch happened less than {$update_interval} seconds ago, not fetching.");
        return $store->getItems($cache_key);
    }

    $lock = $lock ?: new RefreshLock($cache_key);
    $hasLock = $lock->acquire();

    if (!$hasLock) {
        $logger('another cache refresh is already running');

        if ($store->hasPublishedGeneration($cache_key)) {
            return $store->getItems($cache_key);
        }

        $deadline = microtime(true) + $coldCacheWaitSeconds;
        do {
            usleep(250000);

            /** @phpstan-ignore-next-line The store may be published by another process while sleeping. */
            if ($store->hasPublishedGeneration($cache_key)) {
                return $store->getItems($cache_key);
            }

            $hasLock = $lock->acquire();
        } while (!$hasLock && microtime(true) < $deadline);

        if (!$hasLock) {
            $logger('initial cache refresh did not finish before the wait timeout');
            return [];
        }
    }

    try {
        // Another process may have refreshed the cache immediately before this process acquired the lock.
        /** @phpstan-ignore-next-line The store may change in another process before lock acquisition. */
        if ($store->isFresh($cache_key, $clock())) {
            $logger("last fetch happened less than {$update_interval} seconds ago, not fetching.");
            return $store->getItems($cache_key);
        }

        if (is_null($pageFetcher)) {
            $client = get_client();
            /** @var Projects|Tasks|Companies|Deals|Services|People|TimeEntries $resource */
            $resource = new $resource_class($client);
            $pageFetcher = fn (array $pageParameters): array => $resource->getList($pageParameters);
        }

        refresh_generation(
            $store,
            $cache_key,
            $resource_class,
            $parameters,
            $resource_formatter,
            $pageFetcher,
            $update_interval,
            $clock,
            $logger
        );

        $logger('cached items:', $store->countPublishedItems($cache_key));

        return $store->getItems($cache_key);
    } catch (Throwable $error) {
        $logger('fetch failed, keeping stale cache:', $error->getMessage());
        return $store->getItems($cache_key);
    } finally {
        $lock->release();
    }
}

/**
 * @param array<string, mixed> $parameters
 * @param callable(array<string, mixed>): array<string, mixed> $pageFetcher
 * @param callable(): int $clock
 * @param null|callable(mixed...): void $logger
 */
function refresh_generation(
    SqliteStore $store,
    string $cacheKey,
    string $resourceClass,
    array $parameters,
    callable $resourceFormatter,
    callable $pageFetcher,
    int $updateInterval,
    callable $clock,
    ?callable $logger = null
): void {
    $generationId = $store->beginRefresh($cacheKey, $resourceClass, $parameters, $clock());
    $currentPage = 1;
    $totalPages = 1;
    $pageSize = 200;

    try {
        do {
            $pageParameters = [
                'page[size]' => $pageSize,
                'page[number]' => $currentPage,
            ] + $parameters;

            if (!is_null($logger)) {
                $logger('fetch_all_by_resource', [
                    'current_page' => $currentPage,
                    'page_size' => $pageSize,
                    'published_count' => $store->countPublishedItems($cacheKey),
                    'response_pages' => $totalPages,
                ]);
            }

            $response = $pageFetcher($pageParameters);
            $data = $response['data'] ?? null;
            $included = $response['included'] ?? [];

            if (!is_array($data)) {
                throw new RuntimeException('Productive returned an invalid data collection.');
            }

            if (!is_array($included)) {
                throw new RuntimeException('Productive returned an invalid included collection.');
            }

            if (!isset($response['meta']) || !is_array($response['meta'])
                || !array_key_exists('total_pages', $response['meta'])
            ) {
                throw new RuntimeException('Productive returned missing pagination metadata.');
            }

            $responsePages = $response['meta']['total_pages'];
            if (!is_int($responsePages) && !ctype_digit((string)$responsePages)) {
                throw new RuntimeException('Productive returned invalid pagination metadata.');
            }
            $totalPages = (int)$responsePages;

            if ($totalPages < 0 || ($totalPages < $currentPage && !empty($data))) {
                throw new RuntimeException('Productive returned inconsistent pagination metadata.');
            }

            $items = array_values(array_filter(array_map(
                $resourceFormatter,
                merge_relationships($data, $included)
            )));
            $store->stageItems($generationId, $items, $clock());

            $currentPage += 1;
        } while ($currentPage <= $totalPages);

        $store->publishRefresh($cacheKey, $generationId, $clock(), $updateInterval);
    } catch (Throwable $error) {
        try {
            $store->failRefresh($cacheKey, $generationId, $error);
        } catch (Throwable $failure) {
            if (!is_null($logger)) {
                $logger('failed to clean staged cache:', $failure->getMessage());
            }
        }

        throw $error;
    }
}

/**
 * @param array<string, mixed> $parameters
 * @param null|callable(array<string, mixed>): array<string, mixed> $page_fetcher
 * @param null|callable(): int $clock
 * @return array<int, array<string, mixed>>
 */
function get_all_by_resource_from_cache(
    string $resource_class,
    callable $resource_formatter,
    array $parameters = [],
    ?string $company_id = null,
    bool $open_deals_only = false,
    ?SqliteStore $store = null,
    ?callable $page_fetcher = null,
    ?callable $clock = null,
    ?RefreshLock $lock = null,
    float $cold_cache_wait_seconds = 30.0
): array {
    $resource_name = get_resource_name($resource_class);
    $logger = fn (...$args) => logger("[{$resource_name}]", ...$args);
    $logger('get_all_by_resource_from_cache', json_encode($parameters));

    validate_resource_class($resource_class);

    $store = $store ?: new SqliteStore();
    $cache_key = generate_cache_key($resource_class, $parameters);

    if ($store->hasPublishedGeneration($cache_key)) {
        $items = $store->getItems($cache_key, $company_id, $open_deals_only);
        $logger('get_all_by_resource_from_cache', $cache_key, 'hit', count($items));
        return $items;
    }

    $logger('get_all_by_resource_from_cache', $cache_key, 'cache miss');
    fetch_all_by_resource(
        $resource_class,
        $resource_formatter,
        $parameters,
        $store,
        $page_fetcher,
        $clock,
        $lock,
        $cold_cache_wait_seconds
    );

    return $store->getItems($cache_key, $company_id, $open_deals_only);
}

<?php

namespace Alfred\Productive\Tests\Integration;

use Alfred\Productive\Cache\RefreshLock;
use Alfred\Productive\Cache\SqliteStore;
use Alfred\Productive\Resources\Deals;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function Alfred\Productive\Functions\Resources\fetch\fetch_all_by_resource;
use function Alfred\Productive\Functions\Resources\fetch\get_all_by_resource_from_cache;
use function Alfred\Productive\Functions\Resources\fetch\refresh_generation;
use function Alfred\Productive\Functions\cache\generate_cache_key;

class FetchCacheTest extends TestCase
{
    private string $directory;

    private SqliteStore $store;

    private string $logDirectory;

    private bool $createdLogDirectory = false;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alfred-productive-fetch-' . bin2hex(random_bytes(8));
        $this->store = new SqliteStore($this->directory . '/productive.sqlite');
        $this->logDirectory = dirname(__DIR__, 2) . '/logs';

        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0777, true);
            $this->createdLogDirectory = true;
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        if ($this->createdLogDirectory) {
            foreach (glob($this->logDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->logDirectory);
        }
    }

    public function testMultiPageRefreshPublishesOnlyAfterTheLastPage(): void
    {
        $this->publishItems('cache', [$this->item('old')]);
        $pageFetcher = function (array $parameters): array {
            $page = (int)$parameters['page[number]'];
            if ($page === 2) {
                self::assertSame(['old'], $this->uids($this->store->getItems('cache')));
            }

            return [
                'data' => [$this->resource($page === 1 ? 'one' : 'two')],
                'included' => [],
                'meta' => ['total_pages' => 2],
            ];
        };

        refresh_generation(
            $this->store,
            'cache',
            Deals::class,
            [],
            fn (array $resource): array => $this->formatResource($resource),
            $pageFetcher,
            60,
            fn (): int => 200
        );

        self::assertSame(['one', 'two'], $this->uids($this->store->getItems('cache')));
    }

    public function testFailureOnALaterPageKeepsStagedItemsInvisible(): void
    {
        $this->publishItems('cache', [$this->item('old')]);
        $pageFetcher = function (array $parameters): array {
            if ((int)$parameters['page[number]'] === 2) {
                self::assertSame(['old'], $this->uids($this->store->getItems('cache')));
                throw new RuntimeException('Second page failed');
            }

            return [
                'data' => [$this->resource('replacement')],
                'included' => [],
                'meta' => ['total_pages' => 2],
            ];
        };

        try {
            refresh_generation(
                $this->store,
                'cache',
                Deals::class,
                [],
                fn (array $resource): array => $this->formatResource($resource),
                $pageFetcher,
                60,
                fn (): int => 200
            );
            self::fail('The failed page should abort the refresh.');
        } catch (RuntimeException $error) {
            self::assertSame('Second page failed', $error->getMessage());
        }

        self::assertSame(['old'], $this->uids($this->store->getItems('cache')));
    }

    public function testValidEmptyResponsePublishesAnEmptyGeneration(): void
    {
        $this->publishItems('cache', [$this->item('old')]);

        refresh_generation(
            $this->store,
            'cache',
            Deals::class,
            [],
            fn (array $resource): array => $this->formatResource($resource),
            fn (array $parameters): array => [
                'data' => [],
                'included' => [],
                'meta' => ['total_pages' => 0],
            ],
            60,
            fn (): int => 200
        );

        self::assertTrue($this->store->hasPublishedGeneration('cache'));
        self::assertSame([], $this->store->getItems('cache'));
    }

    public function testMissingPaginationMetadataDoesNotReplacePublishedItems(): void
    {
        $this->publishItems('cache', [$this->item('old')]);

        try {
            refresh_generation(
                $this->store,
                'cache',
                Deals::class,
                [],
                fn (array $resource): array => $this->formatResource($resource),
                fn (array $parameters): array => [
                    'data' => [$this->resource('partial')],
                    'included' => [],
                ],
                60,
                fn (): int => 200
            );
            self::fail('Missing pagination metadata must fail the refresh.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('missing pagination metadata', $error->getMessage());
        }

        self::assertSame(['old'], $this->uids($this->store->getItems('cache')));
    }

    public function testMissingPaginationMetadataOnALaterPageDoesNotPublishPartialData(): void
    {
        $this->publishItems('cache', [$this->item('old')]);

        try {
            refresh_generation(
                $this->store,
                'cache',
                Deals::class,
                [],
                fn (array $resource): array => $this->formatResource($resource),
                function (array $parameters): array {
                    if ((int)$parameters['page[number]'] === 1) {
                        return [
                            'data' => [$this->resource('partial')],
                            'meta' => ['total_pages' => 2],
                        ];
                    }

                    return ['data' => [$this->resource('also-partial')]];
                },
                60,
                fn (): int => 200
            );
            self::fail('Missing later-page metadata must fail the refresh.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('missing pagination metadata', $error->getMessage());
        }

        self::assertSame(['old'], $this->uids($this->store->getItems('cache')));
    }

    public function testMalformedResponseDoesNotReplacePublishedItems(): void
    {
        $this->publishItems('cache', [$this->item('old')]);

        try {
            refresh_generation(
                $this->store,
                'cache',
                Deals::class,
                [],
                fn (array $resource): array => $this->formatResource($resource),
                fn (array $parameters): array => ['data' => null],
                60,
                fn (): int => 200
            );
            self::fail('Malformed responses must fail the refresh.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('invalid data collection', $error->getMessage());
        }

        self::assertSame(['old'], $this->uids($this->store->getItems('cache')));
    }

    public function testExpirationStartsWhenTheRefreshCompletes(): void
    {
        $times = [100, 120, 150];
        $clock = function () use (&$times): int {
            return (int)array_shift($times);
        };

        refresh_generation(
            $this->store,
            'cache',
            Deals::class,
            [],
            fn (array $resource): array => $this->formatResource($resource),
            fn (array $parameters): array => [
                'data' => [$this->resource('one')],
                'meta' => ['total_pages' => 1],
            ],
            60,
            $clock
        );

        self::assertTrue($this->store->isFresh('cache', 209));
        self::assertFalse($this->store->isFresh('cache', 210));
    }

    public function testColdReaderWaitsForAnotherProcessToPublish(): void
    {
        $parameters = [];
        $cacheKey = generate_cache_key(Deals::class, $parameters);
        $fixture = dirname(__DIR__) . '/Fixtures/cold-cache-refresh.php';
        $lockDirectory = $this->directory . '/locks';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $fixture, $this->directory . '/productive.sqlite', $lockDirectory, $cacheKey],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        self::assertSame("locked\n", fgets($pipes[1]));

        try {
            $items = fetch_all_by_resource(
                Deals::class,
                fn (array $resource): array => $this->formatResource($resource),
                $parameters,
                $this->store,
                fn (array $parameters): array => throw new RuntimeException('The waiting reader must not fetch.'),
                fn (): int => 120,
                new RefreshLock($cacheKey, $lockDirectory),
                5.0
            );

            self::assertSame(['published-by-owner'], $this->uids($items));
        } finally {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
            self::assertSame(0, $exitCode);
        }
    }

    public function testFreshEmptyPublishedCacheDoesNotTriggerABackgroundRefresh(): void
    {
        $cacheKey = generate_cache_key(Deals::class, []);
        $this->publishItems($cacheKey, []);
        $fetchCount = 0;

        $items = fetch_all_by_resource(
            Deals::class,
            fn (array $resource): array => $this->formatResource($resource),
            [],
            $this->store,
            function (array $parameters) use (&$fetchCount): array {
                $fetchCount += 1;
                return ['data' => [], 'meta' => ['total_pages' => 0]];
            },
            fn (): int => 120,
            new RefreshLock($cacheKey, $this->directory . '/locks')
        );

        self::assertSame([], $items);
        self::assertSame(0, $fetchCount);
    }

    public function testExpiredEmptyPublishedCacheRefreshesNormally(): void
    {
        $cacheKey = generate_cache_key(Deals::class, []);
        $this->publishItems($cacheKey, []);
        $fetchCount = 0;

        $items = fetch_all_by_resource(
            Deals::class,
            fn (array $resource): array => $this->formatResource($resource),
            [],
            $this->store,
            function (array $parameters) use (&$fetchCount): array {
                $fetchCount += 1;
                return [
                    'data' => [$this->resource('new')],
                    'meta' => ['total_pages' => 1],
                ];
            },
            fn (): int => 200,
            new RefreshLock($cacheKey, $this->directory . '/locks')
        );

        self::assertSame(['new'], $this->uids($items));
        self::assertSame(1, $fetchCount);
    }

    public function testFilteredEmptyPublishedCacheDoesNotTriggerARefresh(): void
    {
        $parameters = [];
        $cacheKey = generate_cache_key(Deals::class, $parameters);
        $this->publishItems($cacheKey, [$this->item('one', 'company-a')]);
        $fetchCount = 0;

        $items = get_all_by_resource_from_cache(
            Deals::class,
            fn (array $resource): array => $this->formatResource($resource),
            $parameters,
            'missing-company',
            false,
            $this->store,
            function (array $parameters) use (&$fetchCount): array {
                $fetchCount += 1;
                throw new RuntimeException('A filtered cache hit must not fetch.');
            },
            fn (): int => 120,
            new RefreshLock($cacheKey, $this->directory . '/locks')
        );

        self::assertSame([], $items);
        self::assertSame(0, $fetchCount);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function publishItems(string $cacheKey, array $items): void
    {
        $generation = $this->store->beginRefresh($cacheKey, Deals::class, [], 100);
        $this->store->stageItems($generation, $items, 101);
        $this->store->publishRefresh($cacheKey, $generation, 110, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(string $uid): array
    {
        return [
            'id' => $uid,
            'type' => 'deals',
            'attributes' => ['name' => ucfirst($uid)],
            'relationships' => [],
        ];
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function formatResource(array $resource): array
    {
        return $this->item((string)$resource['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $uid, string $companyId = 'company-a'): array
    {
        return [
            'uid' => $uid,
            'title' => ucfirst($uid),
            'subtitle' => 'Subtitle',
            'match' => $uid,
            'arg' => 'https://example.com/' . $uid,
            'variables' => [
                'company_id' => $companyId,
                'deal_id' => 'deal-' . $uid,
                'relationships' => [
                    'deal' => [
                        'id' => 'deal-' . $uid,
                        'attributes' => ['closed_at' => null],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function uids(array $items): array
    {
        return array_map(fn (array $item): string => (string)$item['uid'], $items);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}

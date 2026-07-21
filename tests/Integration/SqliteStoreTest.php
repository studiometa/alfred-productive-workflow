<?php

namespace Alfred\Productive\Tests\Integration;

use Alfred\Productive\Cache\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqliteStoreTest extends TestCase
{
    private string $directory;

    private string $database;

    private SqliteStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alfred-productive-' . bin2hex(random_bytes(8));
        $this->database = $this->directory . '/productive.sqlite';
        $this->store = new SqliteStore($this->database);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testStagedItemsAreInvisibleUntilAtomicallyPublished(): void
    {
        $first = $this->store->beginRefresh('services', 'Services', [], 100);
        $this->store->stageItems($first, [$this->item('old')], 101);

        self::assertFalse($this->store->hasPublishedGeneration('services'));
        self::assertSame([], $this->store->getItems('services'));

        $this->store->publishRefresh('services', $first, 110, 60);
        self::assertSame(['old'], $this->uids($this->store->getItems('services')));

        $second = $this->store->beginRefresh('services', 'Services', [], 120);
        $this->store->stageItems($second, [$this->item('new')], 121);

        self::assertSame(['old'], $this->uids($this->store->getItems('services')));

        $this->store->publishRefresh('services', $second, 130, 60);
        self::assertSame(['new'], $this->uids($this->store->getItems('services')));
        self::assertSame(1, $this->store->countPublishedItems('services'));
    }

    public function testFailedRefreshKeepsTheCompletePublishedGeneration(): void
    {
        $published = $this->store->beginRefresh('deals', 'Deals', [], 100);
        $this->store->stageItems($published, [$this->item('one'), $this->item('two')], 101);
        $this->store->publishRefresh('deals', $published, 110, 60);

        $failed = $this->store->beginRefresh('deals', 'Deals', [], 200);
        $this->store->stageItems($failed, [$this->item('replacement')], 201);
        $this->store->failRefresh('deals', $failed, new RuntimeException('Page two failed'));

        self::assertSame(['one', 'two'], $this->uids($this->store->getItems('deals')));
    }

    public function testSuccessfulEmptyGenerationIsPublishedAndFresh(): void
    {
        $generation = $this->store->beginRefresh('empty', 'Deals', [], 100);
        $this->store->stageItems($generation, [], 101);
        $this->store->publishRefresh('empty', $generation, 150, 60);

        self::assertTrue($this->store->hasPublishedGeneration('empty'));
        self::assertSame(0, $this->store->countPublishedItems('empty'));
        self::assertSame([], $this->store->getItems('empty'));
        self::assertTrue($this->store->isFresh('empty', 209));
        self::assertFalse($this->store->isFresh('empty', 210));
    }

    public function testCompanyAndOpenDealFiltersCanReturnEmptyWithoutInvalidatingCache(): void
    {
        $generation = $this->store->beginRefresh('services', 'Services', [], 100);
        $this->store->stageItems($generation, [
            $this->item('open', 'company-a'),
            $this->item('closed', 'company-a', '2026-01-01T00:00:00Z'),
            $this->item('unrelated', 'company-b', null, false),
        ], 101);
        $this->store->publishRefresh('services', $generation, 110, 60);

        self::assertSame(['open'], $this->uids($this->store->getItems('services', 'company-a', true)));
        self::assertSame([], $this->store->getItems('services', 'company-b', true));
        self::assertSame([], $this->store->getItems('services', 'missing-company'));
        self::assertTrue($this->store->hasPublishedGeneration('services'));
    }

    public function testEveryStagedItemRequiresAUid(): void
    {
        $generation = $this->store->beginRefresh('invalid', 'Deals', [], 100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty uid');
        $this->store->stageItems($generation, [['title' => 'Missing UID']], 101);
    }

    public function testUnversionedPrototypeDatabaseIsSafelyRebuilt(): void
    {
        $legacyDatabase = $this->directory . '/legacy.sqlite';
        $legacy = new PDO('sqlite:' . $legacyDatabase);
        $legacy->exec(
            'CREATE TABLE cache_entries (
                cache_key TEXT PRIMARY KEY,
                resource_class TEXT NOT NULL,
                parameters_json TEXT NOT NULL,
                status TEXT NOT NULL
            )'
        );
        $legacy->exec(
            'CREATE TABLE items (
                cache_key TEXT NOT NULL,
                uid TEXT NOT NULL,
                PRIMARY KEY (cache_key, uid)
            )'
        );
        $legacy->exec("INSERT INTO cache_entries VALUES ('legacy', 'Deals', '{}', 'fresh')");
        $legacy = null;

        $legacyStore = new SqliteStore($legacyDatabase);
        $pdo = new PDO('sqlite:' . $legacyDatabase);

        self::assertSame(1, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertFalse($legacyStore->hasPublishedGeneration('legacy'));
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $uid,
        string $companyId = 'company-a',
        ?string $closedAt = null,
        bool $relatedDeal = true
    ): array {
        $relationships = [];
        if ($relatedDeal) {
            $relationships['deal'] = [
                'id' => 'deal-' . $uid,
                'attributes' => ['closed_at' => $closedAt],
                'relationships' => [
                    'company' => ['id' => $companyId],
                ],
            ];
        }

        return [
            'uid' => $uid,
            'title' => ucfirst($uid),
            'subtitle' => 'Subtitle',
            'match' => $uid,
            'arg' => 'https://example.com/' . $uid,
            'variables' => [
                'company_id' => $companyId,
                'deal_id' => 'deal-' . $uid,
                'relationships' => $relationships,
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

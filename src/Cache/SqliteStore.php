<?php

namespace Alfred\Productive\Cache;

use PDO;
use RuntimeException;
use Throwable;
use function Alfred\Productive\Functions\utils\get_root_dir;

class SqliteStore
{
    private const SCHEMA_VERSION = 1;

    private PDO $pdo;

    public function __construct(?string $path = null)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite extension is required to use the cache.');
        }

        $path = $path ?: get_root_dir() . '/cache/productive.sqlite';
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create the SQLite cache directory: {$directory}");
        }

        $initLock = fopen($directory . '/sqlite-init.lock', 'c');
        if ($initLock === false) {
            throw new RuntimeException('Could not open the SQLite initialization lock.');
        }

        if (!flock($initLock, LOCK_EX)) {
            fclose($initLock);
            throw new RuntimeException('Could not acquire the SQLite initialization lock.');
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->configure();
            $this->migrate();
        } finally {
            flock($initLock, LOCK_UN);
            fclose($initLock);
        }
    }

    public function hasPublishedGeneration(string $cacheKey): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT published_generation_id FROM cache_entries WHERE cache_key = :cache_key'
        );
        $statement->execute(['cache_key' => $cacheKey]);
        $generationId = $statement->fetchColumn();

        return $generationId !== false && !is_null($generationId);
    }

    public function isFresh(string $cacheKey, int $now): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT published_generation_id, expires_at
            FROM cache_entries
            WHERE cache_key = :cache_key'
        );
        $statement->execute(['cache_key' => $cacheKey]);
        $entry = $statement->fetch();

        return is_array($entry)
            && !is_null($entry['published_generation_id'])
            && !is_null($entry['expires_at'])
            && (int)$entry['expires_at'] > $now;
    }

    public function countPublishedItems(string $cacheKey): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
            FROM cache_entries AS cache
            INNER JOIN items ON items.generation_id = cache.published_generation_id
            WHERE cache.cache_key = :cache_key'
        );
        $statement->execute(['cache_key' => $cacheKey]);

        return (int)$statement->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(string $cacheKey, ?string $companyId = null, bool $openDealsOnly = false): array
    {
        $where = ['cache.cache_key = :cache_key'];
        $parameters = ['cache_key' => $cacheKey];

        if (!is_null($companyId)) {
            $where[] = 'items.company_id = :company_id';
            $parameters['company_id'] = $companyId;
        }

        if ($openDealsOnly) {
            $where[] = 'items.related_deal_id IS NOT NULL';
            $where[] = 'items.closed_at IS NULL';
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT items.item_json
            FROM cache_entries AS cache
            INNER JOIN items ON items.generation_id = cache.published_generation_id
            WHERE %s
            ORDER BY items.title COLLATE NOCASE ASC, items.uid ASC',
            implode(' AND ', $where)
        ));
        $statement->execute($parameters);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $item = json_decode((string)$row['item_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($item)) {
                throw new RuntimeException('The SQLite cache contains an invalid Alfred item.');
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function beginRefresh(
        string $cacheKey,
        string $resourceClass,
        array $parameters,
        int $startedAt
    ): int {
        $this->pdo->beginTransaction();

        try {
            $this->ensureEntry($cacheKey, $resourceClass, $parameters);

            $cleanup = $this->pdo->prepare(
                'DELETE FROM cache_generations
                WHERE cache_key = :cache_key
                AND id != COALESCE(
                    (SELECT published_generation_id FROM cache_entries WHERE cache_key = :cache_key),
                    -1
                )'
            );
            $cleanup->execute(['cache_key' => $cacheKey]);

            $generation = $this->pdo->prepare(
                'INSERT INTO cache_generations (cache_key, started_at, status)
                VALUES (:cache_key, :started_at, :status)'
            );
            $generation->execute([
                'cache_key' => $cacheKey,
                'started_at' => $startedAt,
                'status' => 'staging',
            ]);
            $generationId = (int)$this->pdo->lastInsertId();

            $entry = $this->pdo->prepare(
                'UPDATE cache_entries
                SET last_attempt_at = :last_attempt_at, status = :status, error = NULL
                WHERE cache_key = :cache_key'
            );
            $entry->execute([
                'cache_key' => $cacheKey,
                'last_attempt_at' => $startedAt,
                'status' => 'refreshing',
            ]);

            $this->pdo->commit();

            return $generationId;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function stageItems(int $generationId, array $items, int $updatedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO items (
                generation_id, uid, title, subtitle, match, arg, company_id,
                deal_id, related_deal_id, project_id, service_id, task_id,
                closed_at, item_json, updated_at
            ) VALUES (
                :generation_id, :uid, :title, :subtitle, :match, :arg, :company_id,
                :deal_id, :related_deal_id, :project_id, :service_id, :task_id,
                :closed_at, :item_json, :updated_at
            ) ON CONFLICT(generation_id, uid) DO UPDATE SET
                title = excluded.title,
                subtitle = excluded.subtitle,
                match = excluded.match,
                arg = excluded.arg,
                company_id = excluded.company_id,
                deal_id = excluded.deal_id,
                related_deal_id = excluded.related_deal_id,
                project_id = excluded.project_id,
                service_id = excluded.service_id,
                task_id = excluded.task_id,
                closed_at = excluded.closed_at,
                item_json = excluded.item_json,
                updated_at = excluded.updated_at'
        );

        $this->pdo->beginTransaction();

        try {
            foreach ($items as $item) {
                $uid = $this->stringOrNull($item['uid'] ?? null);
                if (is_null($uid)) {
                    throw new RuntimeException('Every cached Alfred item must have a non-empty uid.');
                }

                $statement->execute([
                    'generation_id' => $generationId,
                    'uid' => $uid,
                    'title' => (string)($item['title'] ?? ''),
                    'subtitle' => (string)($item['subtitle'] ?? ''),
                    'match' => (string)($item['match'] ?? ''),
                    'arg' => (string)($item['arg'] ?? ''),
                    'company_id' => $this->extractCompanyId($item),
                    'deal_id' => $this->stringOrNull($this->arrayGet($item, 'variables.deal_id')),
                    'related_deal_id' => $this->stringOrNull(
                        $this->arrayGet($item, 'variables.relationships.deal.id')
                    ),
                    'project_id' => $this->stringOrNull($this->arrayGet($item, 'variables.project_id')),
                    'service_id' => $this->stringOrNull($this->arrayGet($item, 'variables.service_id')),
                    'task_id' => $this->stringOrNull($this->arrayGet($item, 'variables.task_id')),
                    'closed_at' => $this->extractClosedAt($item),
                    'item_json' => $this->encodeJson($item),
                    'updated_at' => $updatedAt,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function publishRefresh(string $cacheKey, int $generationId, int $completedAt, int $ttl): void
    {
        $expiresAt = $completedAt + $ttl;
        $this->pdo->beginTransaction();

        try {
            $generation = $this->pdo->prepare(
                'UPDATE cache_generations
                SET completed_at = :completed_at, expires_at = :expires_at, status = :status, error = NULL
                WHERE id = :generation_id AND cache_key = :cache_key AND status = :staging_status'
            );
            $generation->execute([
                'generation_id' => $generationId,
                'cache_key' => $cacheKey,
                'completed_at' => $completedAt,
                'expires_at' => $expiresAt,
                'status' => 'published',
                'staging_status' => 'staging',
            ]);

            if ($generation->rowCount() !== 1) {
                throw new RuntimeException('Could not publish the staged cache generation.');
            }

            $entry = $this->pdo->prepare(
                'UPDATE cache_entries
                SET published_generation_id = :generation_id,
                    last_success_at = :last_success_at,
                    expires_at = :expires_at,
                    status = :status,
                    error = NULL
                WHERE cache_key = :cache_key'
            );
            $entry->execute([
                'generation_id' => $generationId,
                'last_success_at' => $completedAt,
                'expires_at' => $expiresAt,
                'status' => 'fresh',
                'cache_key' => $cacheKey,
            ]);

            $cleanup = $this->pdo->prepare(
                'DELETE FROM cache_generations WHERE cache_key = :cache_key AND id != :generation_id'
            );
            $cleanup->execute([
                'cache_key' => $cacheKey,
                'generation_id' => $generationId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function failRefresh(string $cacheKey, int $generationId, Throwable $error): void
    {
        $this->pdo->beginTransaction();

        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM cache_generations WHERE id = :generation_id AND cache_key = :cache_key'
            );
            $delete->execute([
                'generation_id' => $generationId,
                'cache_key' => $cacheKey,
            ]);

            $entry = $this->pdo->prepare(
                'UPDATE cache_entries SET status = :status, error = :error WHERE cache_key = :cache_key'
            );
            $entry->execute([
                'status' => 'failed',
                'error' => $error->getMessage(),
                'cache_key' => $cacheKey,
            ]);

            $this->pdo->commit();
        } catch (Throwable $failure) {
            $this->pdo->rollBack();
            throw $failure;
        }
    }

    private function configure(): void
    {
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    private function migrate(): void
    {
        $version = (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException("Unsupported SQLite cache schema version: {$version}");
        }

        if ($version < self::SCHEMA_VERSION) {
            $this->pdo->beginTransaction();

            try {
                // PR #35 was never released. Its unversioned prototype cache is disposable and rebuilt safely.
                $this->pdo->exec('DROP TABLE IF EXISTS items');
                $this->pdo->exec('DROP TABLE IF EXISTS cache_generations');
                $this->pdo->exec('DROP TABLE IF EXISTS cache_entries');
                $this->createSchema();
                $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
                $this->pdo->commit();
            } catch (Throwable $error) {
                $this->pdo->rollBack();
                throw $error;
            }

            return;
        }

        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache_entries (
                cache_key TEXT PRIMARY KEY,
                resource_class TEXT NOT NULL,
                parameters_json TEXT NOT NULL DEFAULT "{}",
                published_generation_id INTEGER,
                last_success_at INTEGER,
                last_attempt_at INTEGER,
                expires_at INTEGER,
                status TEXT NOT NULL DEFAULT "pending",
                error TEXT
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache_generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cache_key TEXT NOT NULL,
                started_at INTEGER NOT NULL,
                completed_at INTEGER,
                expires_at INTEGER,
                status TEXT NOT NULL,
                error TEXT,
                FOREIGN KEY (cache_key) REFERENCES cache_entries(cache_key) ON DELETE CASCADE
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS items (
                generation_id INTEGER NOT NULL,
                uid TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT "",
                subtitle TEXT NOT NULL DEFAULT "",
                match TEXT NOT NULL DEFAULT "",
                arg TEXT NOT NULL DEFAULT "",
                company_id TEXT,
                deal_id TEXT,
                related_deal_id TEXT,
                project_id TEXT,
                service_id TEXT,
                task_id TEXT,
                closed_at TEXT,
                item_json TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (generation_id, uid),
                FOREIGN KEY (generation_id) REFERENCES cache_generations(id) ON DELETE CASCADE
            )'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS cache_generations_cache_status_idx
            ON cache_generations(cache_key, status)'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS items_generation_company_idx
            ON items(generation_id, company_id)'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS items_generation_open_deal_idx
            ON items(generation_id, related_deal_id, closed_at)'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS items_generation_title_idx
            ON items(generation_id, title COLLATE NOCASE, uid)'
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function ensureEntry(string $cacheKey, string $resourceClass, array $parameters): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cache_entries (cache_key, resource_class, parameters_json, status)
            VALUES (:cache_key, :resource_class, :parameters_json, :status)
            ON CONFLICT(cache_key) DO UPDATE SET
                resource_class = excluded.resource_class,
                parameters_json = excluded.parameters_json'
        );
        $statement->execute([
            'cache_key' => $cacheKey,
            'resource_class' => $resourceClass,
            'parameters_json' => $this->encodeJson($parameters),
            'status' => 'pending',
        ]);
    }

    /**
     * @param array<string, mixed> $array
     */
    private function arrayGet(array $array, string $path): mixed
    {
        $current = $array;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractCompanyId(array $item): ?string
    {
        return $this->stringOrNull($this->arrayGet($item, 'variables.company_id'))
            ?? $this->stringOrNull($this->arrayGet($item, 'variables.relationships.company.id'))
            ?? $this->stringOrNull($this->arrayGet($item, 'variables.relationships.deal.relationships.company.id'));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractClosedAt(array $item): ?string
    {
        return $this->stringOrNull($this->arrayGet($item, 'variables.relationships.deal.attributes.closed_at'));
    }

    /**
     * @param array<mixed> $data
     */
    private function encodeJson(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return (string)$value;
    }
}

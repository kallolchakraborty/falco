<?php // src/Middleware/SqliteRateLimitStore.php
namespace Falco\Middleware;

/**
 * {@see RateLimitStoreInterface} backed by a SQLite table so rate-limit
 * windows survive across php-fpm workers. Uses a transaction + ON CONFLICT
 * upsert to stay correct under concurrency.
 */
final class SqliteRateLimitStore implements RateLimitStoreInterface
{
    private \PDO $pdo;

    public function __construct(string $dsn = 'sqlite::memory:', ?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limit (
                rl_key TEXT PRIMARY KEY,
                hits TEXT NOT NULL,
                window_start INTEGER NOT NULL
            )'
        );
    }

    public function increment(string $key, int $windowSeconds): int
    {
        $now = time();
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->prepare('SELECT hits, window_start FROM rate_limit WHERE rl_key = ?');
            $row->execute([$key]);
            $data = $row->fetch(\PDO::FETCH_ASSOC);

            if ($data === false || (int) $data['window_start'] < $now - $windowSeconds) {
                $hits = [(string) $now];
                $this->pdo->prepare(
                    'INSERT INTO rate_limit (rl_key, hits, window_start) VALUES (?, ?, ?)
                     ON CONFLICT(rl_key) DO UPDATE SET hits = excluded.hits, window_start = excluded.window_start'
                )->execute([$key, json_encode($hits), $now]);
            } else {
                $hits = array_values(array_filter(
                    json_decode($data['hits'], true) ?: [],
                    fn (int $t): bool => $t > $now - $windowSeconds,
                ));
                $hits[] = $now;
                $this->pdo->prepare('UPDATE rate_limit SET hits = ? WHERE rl_key = ?')
                    ->execute([json_encode($hits), $key]);
            }
            $this->pdo->commit();
            return count($hits);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

<?php // src/Middleware/InMemoryRateLimitStore.php
namespace Falco\Middleware;

/**
 * Default per-process sliding-window store. Sufficient for a single
 * `php -S` / Swoole worker; use {@see SqliteRateLimitStore} for
 * multi-worker php-fpm where the window must be shared.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    private array $hits = [];

    public function increment(string $key, int $windowSeconds): int
    {
        $now = time();
        $this->hits[$key] = array_values(array_filter(
            $this->hits[$key] ?? [],
            fn (int $t): bool => $t > $now - $windowSeconds,
        ));
        $this->hits[$key][] = $now;
        return count($this->hits[$key]);
    }
}

<?php // src/Middleware/RateLimitStoreInterface.php
namespace Falco\Middleware;

/**
 * Backing store for {@see RateLimitMiddleware}. `increment` returns the
 * request count for this key within the window; implementations decide
 * whether the window state is in-memory or persisted (e.g. SQLite).
 */
interface RateLimitStoreInterface
{
    public function increment(string $key, int $windowSeconds): int;
}

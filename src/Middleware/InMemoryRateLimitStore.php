<?php // src/Middleware/InMemoryRateLimitStore.php
namespace Falco\Middleware;

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
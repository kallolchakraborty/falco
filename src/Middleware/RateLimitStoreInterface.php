<?php // src/Middleware/RateLimitStoreInterface.php
namespace Falco\Middleware;

interface RateLimitStoreInterface
{
    public function increment(string $key, int $windowSeconds): int;
}

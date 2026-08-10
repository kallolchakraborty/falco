<?php // src/Middleware/RateLimitMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

/**
 * Per-IP sliding-window rate limiter. On the first request that would exceed
 * the limit it returns 429 with `Retry-After`; otherwise it decrements
 * `X-RateLimit-Remaining` on the response.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimitStoreInterface $store,
        private int $maxRequests,
        private int $windowSeconds = 60,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $key = 'ip:' . ($request->ip ?: 'unknown');
        $count = $this->store->increment($key, $this->windowSeconds);
        if ($count > $this->maxRequests) {
            $res = Response::json(['detail' => 'Rate limit exceeded'], 429);
            $res->headers['retry-after'] = (string) $this->windowSeconds;
            $res->headers['x-rate-limit-remaining'] = '0';
            return $res;
        }
        $res = $next($request);
        $res->headers['x-rate-limit-remaining'] = (string) ($this->maxRequests - $count);
        return $res;
    }
}

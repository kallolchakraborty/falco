<?php // src/Middleware/RequestIdMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $existing = $request->headers['x-request-id'] ?? $request->attributes['request_id'] ?? null;
        $validated = $existing !== null && preg_match('/^[A-Za-z0-9-_]{1,64}$/', (string) $existing)
            ? $existing : $this->generate();
        $res = $next($request->with('request_id', $validated));
        $res->headers['x-request-id'] = $validated;
        return $res;
    }

    private function generate(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
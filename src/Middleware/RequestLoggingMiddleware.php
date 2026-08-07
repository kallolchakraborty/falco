<?php // src/Middleware/RequestLoggingMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;
use Falco\Logging\LoggerInterface;

final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(Request $request, callable $next): Response
    {
        $start = microtime(true);
        $res = $next($request);
        $this->logger->info('request', [
            'method' => $request->method,
            'path' => $request->path,
            'status' => $res->status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'request_id' => $request->attributes['request_id'] ?? null,
        ]);
        return $res;
    }
}
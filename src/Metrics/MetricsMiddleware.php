<?php // src/Metrics/MetricsMiddleware.php
namespace Falco\Metrics;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(private Registry $registry) {}

    public function handle(Request $request, callable $next): Response
    {
        $counter = $this->registry->counter('falco_http_requests_total', 'total requests');
        $hist = $this->registry->histogram('falco_http_request_duration_seconds', 'request latency');
        $start = microtime(true);
        $res = $next($request);
        $counter->inc(['method' => $request->method, 'status' => (string) $res->status]);
        $hist->observe(round(microtime(true) - $start, 6), ['method' => $request->method]);
        return $res;
    }
}
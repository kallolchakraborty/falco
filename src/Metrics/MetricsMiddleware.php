<?php // src/Metrics/MetricsMiddleware.php
namespace Falco\Metrics;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

/**
 * Records `falco_http_requests_total` (count by method+status) and
 * `falco_http_request_duration_seconds` (latency histogram) into a
 * {@see Registry}, which `/metrics` then formats for Prometheus.
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    private Counter $requestCounter;
    private Histogram $requestDuration;

    public function __construct(Registry $registry)
    {
        $this->requestCounter = $registry->counter('falco_http_requests_total', 'total requests');
        $this->requestDuration = $registry->histogram('falco_http_request_duration_seconds', 'request latency');
    }

    public function handle(Request $request, callable $next): Response
    {
        $start = microtime(true);
        $res = $next($request);
        $this->requestCounter->inc(['method' => $request->method, 'status' => (string) $res->status]);
        $this->requestDuration->observe(round(microtime(true) - $start, 6), ['method' => $request->method]);
        return $res;
    }
}


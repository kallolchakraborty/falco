<?php // tests/MetricsTest.php
namespace Falco\Tests;

use Falco\Metrics\Registry;
use Falco\Metrics\Histogram;
use Falco\Metrics\PrometheusTextFormatter;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function testExportsText(): void
    {
        $registry = new Registry();
        $registry->counter('requests_total', 'total')->inc();
        $txt = (new PrometheusTextFormatter())->format($registry);
        $this->assertStringContainsString('# TYPE requests_total counter', $txt);
        $this->assertStringContainsString('requests_total 1', $txt);
    }

    public function testHistogramExportsWithLabels(): void
    {
        $registry = new Registry();
        $h = $registry->histogram('http_latency', 'request latency', ['0.01', '0.1']);
        $h->observe(0.05, ['method' => 'GET']);
        $h->observe(0.15, ['method' => 'POST']);
        $txt = (new PrometheusTextFormatter())->format($registry);
        $this->assertStringContainsString('# TYPE http_latency histogram', $txt);
        $this->assertStringContainsString('http_latency_bucket{method="GET",le="0.01"} 0', $txt);
        $this->assertStringContainsString('http_latency_bucket{method="GET",le="0.1"} 1', $txt);
        $this->assertStringContainsString('http_latency_bucket{method="POST",le="0.01"} 0', $txt);
        $this->assertStringContainsString('http_latency_bucket{method="POST",le="0.1"} 0', $txt);
        $this->assertStringContainsString('http_latency_bucket{method="POST",le="0.25"} 1', $txt);
        $this->assertStringContainsString('http_latency_sum{method="GET"} 0.05', $txt);
        $this->assertStringContainsString('http_latency_count{method="GET"} 1', $txt);
        $this->assertStringContainsString('http_latency_sum{method="POST"} 0.15', $txt);
        $this->assertStringContainsString('http_latency_count{method="POST"} 1', $txt);
    }
}

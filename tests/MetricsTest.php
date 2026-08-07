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
}
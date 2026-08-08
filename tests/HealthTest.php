<?php // tests/HealthTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\Health\HealthController;
use Falco\Request;
use PHPUnit\Framework\TestCase;

final class HealthTest extends TestCase
{
    public function testLiveness(): void
    {
        $app = new App(docs: false);
        HealthController::register($app, []);
        $res = $app->handle(new Request('GET', '/health/live', [], [], []));
        $this->assertSame(200, $res->status);
    }

    public function testReadinessOk(): void
    {
        $app = new App(docs: false);
        HealthController::register($app, [fn () => true]);
        $res = $app->handle(new Request('GET', '/health/ready', [], [], []));
        $this->assertSame(200, $res->status);
    }

    public function testReadinessFails(): void
    {
        $app = new App(docs: false);
        HealthController::register($app, ['db' => fn () => false]);
        $res = $app->handle(new Request('GET', '/health/ready', [], [], []));
        $this->assertSame(503, $res->status);
    }
}

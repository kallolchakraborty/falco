<?php // tests/MiddlewarePipelineTest.php
namespace Falco\Tests;

use Falco\Request;
use Falco\Response;
use Falco\Http\MiddlewarePipeline;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    public function testPipelineRunsAllLayersInOrder(): void
    {
        $order = [];
        $mw1 = function (Request $r, callable $next) use (&$order): Response {
            $order[] = 'm1';
            return $next($r);
        };
        $mw2 = function (Request $r, callable $next) use (&$order): Response {
            $order[] = 'm2';
            return $next($r);
        };
        $terminal = function (Request $r) use (&$order): Response {
            $order[] = 'terminal';
            return new Response();
        };
        $pipeline = new MiddlewarePipeline([$mw1, $mw2], $terminal);
        $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(['m1', 'm2', 'terminal'], $order);
    }

    public function testPipelineEmptyRunsTerminal(): void
    {
        $terminal = fn(Request $r): Response => Response::json(['ok' => true]);
        $pipeline = new MiddlewarePipeline([], $terminal);
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(['ok' => true], $res->body);
    }
}
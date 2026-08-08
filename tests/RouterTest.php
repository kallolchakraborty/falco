<?php // tests/RouterTest.php
namespace Falco\Tests;

use Falco\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testExactMatch(): void
    {
        $router = new Router();
        $handler = fn() => 'ok';
        $router->add('GET', '/items', $handler);
        $match = $router->match('GET', '/items');
        $this->assertNotNull($match);
        $this->assertSame([], $match->pathParams);
    }

    public function testTemplateMatch(): void
    {
        $router = new Router();
        $router->add('GET', '/items/{item_id}', fn() => null);
        $match = $router->match('GET', '/items/42');
        $this->assertSame(['item_id' => '42'], $match->pathParams);
    }

    public function testNoMatch(): void
    {
        $router = new Router();
        $router->add('GET', '/items', fn() => null);
        $this->assertNull($router->match('GET', '/nope'));
        $this->assertNull($router->match('POST', '/items'));
    }
}

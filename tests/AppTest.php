<?php // tests/AppTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\Request;
use Falco\HttpException;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App(title: 'Test API', version: '1.0', docs: false);
    }

    public function testGetRoute(): void
    {
        $this->app->get('/hello', fn() => ['msg' => 'hi']);
        $res = $this->app->handle(new Request('GET', '/hello', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertSame(['msg' => 'hi'], $res->body);
    }

    public function testPathParamCoercion(): void
    {
        $this->app->get('/items/{item_id}', fn(int $item_id) => ['id' => $item_id]);
        $res = $this->app->handle(new Request('GET', '/items/7', [], [], []));
        $this->assertSame(['id' => 7], $res->body);
    }

    public function testNotFound(): void
    {
        $res = $this->app->handle(new Request('GET', '/missing', [], [], []));
        $this->assertSame(404, $res->status);
        $this->assertSame(['detail' => 'Not Found'], $res->body);
    }

    public function testValidationError422(): void
    {
        $this->app->post('/items', function (#[\Falco\Params\Body] Item $item) { return $item; });
        $res = $this->app->handle(new Request('POST', '/items', [], [], ['name' => 'x']));
        $this->assertSame(422, $res->status);
        $this->assertSame('missing', $res->body['detail'][0]['type']);
    }

    public function testHttpException(): void
    {
        $this->app->get('/secret', function () { throw new HttpException(403, 'Forbidden'); });
        $res = $this->app->handle(new Request('GET', '/secret', [], [], []));
        $this->assertSame(403, $res->status);
        $this->assertSame(['detail' => 'Forbidden'], $res->body);
    }

    public function testResponsePassThrough(): void
    {
        $this->app->get('/raw', fn() => \Falco\Response::json(['a' => 1], 201));
        $res = $this->app->handle(new Request('GET', '/raw', [], [], []));
        $this->assertSame(201, $res->status);
    }

    public function testModelReturnSerialized(): void
    {
        $this->app->get('/item', fn(): Item => Item::fromArray(['name' => 'W', 'price' => 2]));
        $res = $this->app->handle(new Request('GET', '/item', [], [], []));
        $this->assertSame(['name' => 'W', 'price' => 2.0], $res->body);
    }
}
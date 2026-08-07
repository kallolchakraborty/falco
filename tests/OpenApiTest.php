<?php // tests/OpenApiTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\OpenAPI\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

final class OpenApiTest extends TestCase
{
    public function testGeneratesOpenApi(): void
    {
        $app = new App(title: 'Items', version: '2.0', docs: false);
        $app->get('/items/{item_id}', function (int $item_id, ?string $q = null): Item {
            return Item::fromArray(['name' => 'x', 'price' => 1]);
        }, responseModel: Item::class);
        $doc = (new OpenApiGenerator())->generate($app);
        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Items', $doc['info']['title']);
        $this->assertArrayHasKey('/items/{item_id}', $doc['paths']);
        $op = $doc['paths']['/items/{item_id}']['get'];
        $this->assertSame('path', $op['parameters'][0]['in']);
        $this->assertSame('query', $op['parameters'][1]['in']);
        $this->assertArrayHasKey('Item', $doc['components']['schemas']);
    }

    public function testDocsEndpoints(): void
    {
        $app = new App(docs: true);
        $this->assertSame(200, $app->handle(new \Falco\Request('GET', '/openapi.json', [], [], []))->status);
        $this->assertSame(200, $app->handle(new \Falco\Request('GET', '/docs', [], [], []))->status);
    }

    public function testNoContentWhenNoResponseModel(): void
    {
        $app = new App(docs: false);
        $app->get('/plain', fn() => ['a' => 1]);
        $doc = (new OpenApiGenerator())->generate($app);
        $this->assertArrayNotHasKey('content', $doc['paths']['/plain']['get']['responses']['200']);
    }

    public function testDependsParamNotInOpenApi(): void
    {
        $app = new App(docs: false);
        $app->get('/db', function (#[\Falco\Params\Depends] Database $db): array { return ['dsn' => $db->dsn]; });
        $doc = (new OpenApiGenerator())->generate($app);
        $this->assertSame([], $doc['paths']['/db']['get']['parameters']);
    }
}
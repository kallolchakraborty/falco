<?php // tests/RequestTest.php
namespace Falco\Tests;

use Falco\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testConstruction(): void
    {
        $req = new Request('GET', '/items/1', ['q' => 'x'], [], []);
        $this->assertSame('/items/1', $req->path);
        $this->assertSame('x', $req->query['q']);
    }

    public function testWithAddsAttributeAndPreservesOriginal(): void
    {
        $req = new Request('GET', '/a', [], [], []);
        $with = $req->with('user', 42);
        $this->assertSame([], $req->attributes);
        $this->assertSame(['user' => 42], $with->attributes);
    }

    public function testIpFromConstructor(): void
    {
        $req = new Request('GET', '/a', [], [], [], [], '1.2.3.4');
        $this->assertSame('1.2.3.4', $req->ip);
    }
}

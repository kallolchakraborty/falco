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
}

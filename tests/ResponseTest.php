<?php // tests/ResponseTest.php
namespace Falco\Tests;

use Falco\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJson(): void
    {
        $r = Response::json(['ok' => true], 201);
        $this->assertSame(201, $r->status);
        $this->assertSame(['ok' => true], $r->body);
    }

    public function testText(): void
    {
        $r = Response::text('hello');
        $this->assertSame(200, $r->status);
        $this->assertSame('hello', $r->body);
        $this->assertSame(['content-type' => 'text/plain; charset=utf-8'], $r->headers);

        $r404 = Response::text('nope', 404);
        $this->assertSame(404, $r404->status);
    }
}

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
}

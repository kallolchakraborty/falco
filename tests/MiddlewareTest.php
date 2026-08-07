<?php // tests/MiddlewareTest.php
namespace Falco\Tests;

use Falco\Http\MiddlewarePipeline;
use Falco\Middleware\RequestIdMiddleware;
use Falco\Middleware\ErrorHandlerMiddleware;
use Falco\Middleware\RequestLoggingMiddleware;
use Falco\Request;
use Falco\Response;
use Falco\Logging\Logger;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private function through(\Falco\Http\MiddlewareInterface|callable $mw, Request $req): Response
    {
        $pipeline = new MiddlewarePipeline([$mw], fn (Request $r): Response => new Response(200, [], ['ok' => true]));
        return $pipeline->handle($req);
    }

    public function testRequestIdSetsHeaderAndEchoes(): void
    {
        $res = $this->through(new RequestIdMiddleware(), new Request('GET', '/', [], [], []));
        $this->assertSame(1, preg_match('/^[0-9a-f-]{36}$/', $res->headers['x-request-id']));
    }

    public function testRequestIdAcceptsValidHeader(): void
    {
        $res = $this->through(new RequestIdMiddleware(), new Request('GET', '/', [], ['x-request-id' => 'abc-123'], []));
        $this->assertSame('abc-123', $res->headers['x-request-id']);
    }

    public function testErrorHandlerMapsValidationTo422(): void
    {
        $pipeline = new MiddlewarePipeline([new ErrorHandlerMiddleware()],
            fn (Request $r): Response => throw new \Falco\Validation\ValidationException([['loc' => ['x'], 'msg' => 'bad', 'type' => 'x']]));
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(422, $res->status);
        $this->assertSame('bad', $res->body['detail'][0]['msg']);
    }

    public function testErrorHandlerMapsHttpToStatusCode(): void
    {
        $pipeline = new MiddlewarePipeline([new ErrorHandlerMiddleware()],
            fn (Request $r): Response => throw new \Falco\HttpException(418, 'teapot'));
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(418, $res->status);
        $this->assertSame('teapot', $res->body['detail']);
    }

    public function testErrorHandlerMapsThrowableTo500(): void
    {
        $pipeline = new MiddlewarePipeline([new ErrorHandlerMiddleware()],
            fn (Request $r): Response => throw new \RuntimeException('boom-msg'));
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertSame('Internal Server Error', $res->body['detail']);
    }

    public function testErrorHandlerDebugShowsMessageWhenDebug(): void
    {
        $pipeline = new MiddlewarePipeline([new ErrorHandlerMiddleware(debug: true)],
            fn (Request $r): Response => throw new \RuntimeException('boom-msg'));
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertSame('boom-msg', $res->body['detail']);
    }

    public function testRequestLoggingEmitsLine(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream);
        $res = $this->through(new RequestLoggingMiddleware($log), new Request('GET', '/a', [], [], []));
        rewind($stream);
        $line = json_decode(stream_get_contents($stream), true);
        $this->assertSame('GET', $line['method']);
        $this->assertSame('/a', $line['path']);
        $this->assertSame(200, $line['status']);
        $this->assertArrayHasKey('duration_ms', $line);
    }
}
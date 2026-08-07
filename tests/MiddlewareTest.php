<?php // tests/MiddlewareTest.php
namespace Falco\Tests;

use Falco\Http\MiddlewarePipeline;
use Falco\Middleware\CorsMiddleware;
use Falco\Middleware\SecurityHeadersMiddleware;
use Falco\Middleware\InMemoryRateLimitStore;
use Falco\Middleware\RateLimitMiddleware;
use Falco\Middleware\RequestIdMiddleware;
use Falco\Middleware\ErrorHandlerMiddleware;
use Falco\Middleware\RequestLoggingMiddleware;
use Falco\Middleware\AuthMiddleware;
use Falco\Request;
use Falco\Security\JwtService;
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

    public function testCorsAllowOrigin(): void
    {
        $res = $this->through(new CorsMiddleware(['https://app.example.com']),
            new Request('GET', '/', [], ['origin' => 'https://app.example.com'], []));
        $this->assertSame('https://app.example.com', $res->headers['access-control-allow-origin']);
    }

    public function testCorsDeniesUnknownOrigin(): void
    {
        $req = new Request('GET', '/', [], ['origin' => 'https://evil.io'], []);
        $res = $this->through(new CorsMiddleware(['https://ok.com']), $req);
        $this->assertArrayNotHasKey('access-control-allow-origin', $res->headers);
    }

    public function testCorsPreflightReturns204WithoutNext(): void
    {
        $called = false;
        $pipeline = new MiddlewarePipeline([new CorsMiddleware(['https://ok.com'])],
            function (Request $r) use (&$called): Response { $called = true; return new Response(200); });
        $req = new Request('OPTIONS', '/', [], ['origin' => 'https://ok.com', 'access-control-request-method' => 'GET'], []);
        $res = $pipeline->handle($req);
        $this->assertSame(204, $res->status);
        $this->assertSame('https://ok.com', $res->headers['access-control-allow-origin']);
        $this->assertFalse($called);
    }

    public function testCorsPreflightDeniedOrigin(): void
    {
        $req = new Request('OPTIONS', '/', [], ['origin' => 'https://evil.io', 'access-control-request-method' => 'GET'], []);
        $res = $this->through(new CorsMiddleware(['https://ok.com']), $req);
        $this->assertSame(204, $res->status);
        $this->assertArrayNotHasKey('access-control-allow-origin', $res->headers);
    }

    public function testSecurityHeadersPresent(): void
    {
        $res = $this->through(new SecurityHeadersMiddleware(), new Request('GET', '/', [], [], []));
        $this->assertSame('nosniff', $res->headers['x-content-type-options']);
        $this->assertSame('DENY', $res->headers['x-frame-options']);
        $this->assertSame('max-age=31536000', $res->headers['strict-transport-security']);
    }

    public function testRateLimitBlocksThirdRequest(): void
    {
        $store = new InMemoryRateLimitStore();
        $mw = new RateLimitMiddleware($store, 2, 60);
        $req = new Request('GET', '/', [], [], []);
        $this->assertSame(200, $this->through($mw, $req)->status);
        $this->assertSame(200, $this->through($mw, $req)->status);
        $res = $this->through($mw, $req);
        $this->assertSame(429, $res->status);
        $this->assertArrayHasKey('retry-after', $res->headers);
    }

    public function testAuthRejectsMissingToken(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $res = $this->through(new AuthMiddleware($jwt), new Request('GET', '/', [], [], []));
        $this->assertSame(401, $res->status);
    }

    public function testAuthAcceptsValidToken(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $token = $jwt->encode(['sub' => '7'], 30);
        $req = new Request('GET', '/', [], ['authorization' => "Bearer $token"], []);
        $res = $this->through(new AuthMiddleware($jwt), $req);
        $this->assertSame(200, $res->status);
    }

    public function testAuthStoresClaimsOnRequest(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $token = $jwt->encode(['sub' => '7', 'role' => 'admin'], 30);
        $pipeline = new MiddlewarePipeline([new AuthMiddleware($jwt)],
            fn (Request $r): Response => new Response(200, [], $r->attributes['user'] ?? []));
        $req = new Request('GET', '/', [], ['authorization' => "Bearer $token"], []);
        $res = $pipeline->handle($req);
        $this->assertSame('7', $res->body['sub']);
        $this->assertSame('admin', $res->body['role']);
    }

    public function testAuthInvalidTokenThrows(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $res = $this->through(new AuthMiddleware($jwt),
            new Request('GET', '/', [], ['authorization' => 'Bearer garbage.token.sig'], []));
        $this->assertSame(401, $res->status);
    }

    public function testAuthOptionalPassesThroughWithoutClaims(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $seen = null;
        $pipeline = new MiddlewarePipeline([new AuthMiddleware($jwt, required: false)],
            function (Request $r) use (&$seen): Response { $seen = $r->attributes['user'] ?? null; return new Response(200); });
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertNull($seen);
    }
}
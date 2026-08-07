# Falco Production-Ready Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn Falco into a production-ready framework (middleware, JWT auth, logging, metrics, health, rate limiting) with a hardened SQLite-backed example app — zero runtime dependencies.

**Architecture:** Middleware onion over the existing Router/ParamResolver dispatch. Each middleware is a small class in `Falco\Middleware`. Auth via HS256 JWT (stdlib `hash_hmac`), refresh tokens stored hashed. All new framework features live in new namespaces under `src/`; the example app rewires onto SQLite via a thin `Falco\Data\Connection` PDO wrapper.

**Tech Stack:** PHP >= 8.1, PHPUnit 12 (dev), PDO SQLite. No runtime deps.

## Global Constraints

- Zero runtime dependencies, enforced. stdlib only: `hash_hmac`, `random_bytes`, `json_encode/decode`, PDO, `password_hash`, `hash_equals`.
- PHP >= 8.1. Composer autoload PSR-4 `Falco\` → `src/`; `Falco\Tests\` → `tests/`.
- Error shapes: 404 `{"detail":"Not Found"}`, 422 `{"detail":[{loc,msg,type}]}`, HttpException → its code+message, 500 `Internal Server Error` (message in debug).
- `App::get/post/put/patch/delete(string $path, callable $handler, ?string $responseModel = null, array $options = [])`. Existing 3-arg callers must keep working unchanged.
- Middleware order = registration order, global then per-route.
- JWT: HS256, secret >= 32 bytes. Refresh tokens: 32 random bytes, SHA-256 stored at rest.
- Passwords hashed with `password_hash` (bcrypt default).
- CORS/security-headers/rate-limit/metrics governed by config booleans; never hard-enabled.
- `Request`/`Response` stay Falco value objects. No PSR-7/PSR-15.
- Do not add keys/sig: reuse helpers where they exist (`Request`, `Response`, `HttpException`, `ValidationException`, `App::get`).

---
## File Map

New:
- `src/Config/Config.php`
- `src/Logging/LoggerInterface.php`, `src/Logging/Logger.php`
- `src/Http/MiddlewareInterface.php`, `src/Http/MiddlewarePipeline.php`
- `src/Middleware/RequestIdMiddleware.php`, `ErrorHandlerMiddleware.php`, `RequestLoggingMiddleware.php`, `CorsMiddleware.php`, `SecurityHeadersMiddleware.php`, `RateLimitMiddleware.php`, `RateLimitStoreInterface.php`, `InMemoryRateLimitStore.php`, `AuthMiddleware.php`
- `src/Security/JwtService.php`, `JwtException.php`, `JwtClaims.php`, `RefreshTokenStoreInterface.php`
- `src/Health/HealthController.php`
- `src/Metrics/Registry.php`, `Counter.php`, `Histogram.php`, `PrometheusTextFormatter.php`, `MetricsMiddleware.php`
- `src/Data/Connection.php`, `src/Data/RefreshTokenRepository.php`
- `examples/items/migrations/001_init.sql`, `src/Data/*` repo; `.env.example`
- Tests: `tests/ConfigTest.php`, `tests/LoggingTest.php`, `tests/MiddlewarePipelineTest.php`, `tests/MiddlewareTest.php`, `tests/JwtTest.php`, `tests/HealthTest.php`, `tests/MetricsTest.php`, `tests/DataTest.php`, `tests/IntegrationTest.php`
- `docs/PRODUCTION.md`

Modified:
- `src/Request.php` (add `attributes` + `ip`, `with()`, `fromGlobals` ip)
- `src/Response.php` (add `text()`)
- `src/Params/ParamResolver.php` (resolve `JwtClaims`)
- `src/App.php` (pipeline + per-route middleware + health/metrics wiring)
- `src/Route.php`, `src/Router.php` (options)
- `src/Runtime/SwooleRuntime.php` (request ip + attributes passthrough)
- `examples/items/app.php` (rewrite: sqlite + auth)
- `README.md`

---

### Task 1: Request/Response extensions + Http core

**Files:**
- Create: `src/Http/MiddlewareInterface.php`, `src/Http/MiddlewarePipeline.php`
- Modify: `src/Request.php`, `src/Response.php`

**Interfaces:**
- Consumes: existing `Falco\Request` (public readonly `method`, `path`, `query`, `headers`, `body`), existing `Falco\Response`.
- Produces: `Request::with(string $key, mixed $value): self`, `Request::$attributes` (array), `Request::$ip` (string), `Response::text(string $content, int $status = 200): self`, `MiddlewareInterface::handle(Request $request, callable $next): Response`, `MiddlewarePipeline::handle(Request $request): Response`.

- [ ] **Step 1: Add failing tests**

```php
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
        $mw1 = function (Request $r, callable $next): Response {
            $order[] = 'm1';
            return $next($r);
        };
        $mw2 = function (Request $r, callable $next): Response {
            $order[] = 'm2';
            return $next($r);
        };
        $terminal = function (Request $r): Response {
            $order[] = 'terminal';
            return new Response();
        };
        $pipeline = new MiddlewarePipeline([$mw1, $mw2], $terminal);
        $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(['m1', 'm2', 'terminal'], $order);
    }

    public function testPipelineEmptyRunsTerminal(): void
    {
        $terminal = fn (Request $r): Response => Response::json(['ok' => true]);
        $pipeline = new MiddlewarePipeline([], $terminal);
        $res = $pipeline->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(['ok' => true], $res->body);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/MiddlewarePipelineTest.php`
Expected: FAIL (class `Falco\Http\MiddlewarePipeline` not found)

- [ ] **Step 3: Write minimal implementation**

```php
// src/Http/MiddlewareInterface.php
<?php
namespace Falco\Http;

use Falco\Request;
use Falco\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
```

```php
// src/Http/MiddlewarePipeline.php
<?php
namespace Falco\Http;

use Falco\Request;
use Falco\Response;

final class MiddlewarePipeline
{
    /** @var (MiddlewareInterface|callable)[] */
    private array $middleware;

    public function __construct(array $middleware, private callable $terminal)
    {
        $this->middleware = array_values($middleware);
    }

    public function handle(Request $request): Response
    {
        return $this->invoke(0, $request);
    }

    private function invoke(int $index, Request $request): Response
    {
        if ($index >= count($this->middleware)) {
            return ($this->terminal)($request);
        }
        $mw = $this->middleware[$index];
        $next = fn (Request $r): Response => $this->invoke($index + 1, $r);
        if ($mw instanceof MiddlewareInterface) {
            return $mw->handle($request, $next);
        }
        return $mw($request, $next);
    }
}
```

```php
// src/Request.php — add two props + with()
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $body,
        public readonly array $attributes = [],
        public readonly string $ip = '',
    ) {}

    public function with(string $key, mixed $value): self
    {
        return new self(
            $this->method, $this->path, $this->query, $this->headers, $this->body,
            [...$this->attributes, $key => $value],
            $this->ip,
        );
    }

    public static function fromGlobals(): self
    {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) $body = [];
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $_GET,
            $headers,
            $body,
            [],
            $_SERVER['REMOTE_ADDR'] ?? '',
        );
    }
}
```

```php
// src/Response.php — add text()
public static function text(string $content, int $status = 200): self
{
    return new self($status, ['content-type' => 'text/plain; charset=utf-8'], $content);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/MiddlewarePipelineTest.php`
Expected: 2 tests, PASS.

- [ ] **Step 5: Wire the pipeline into App + Router + Route (options + middleware)**

```php
// src/Route.php — add options
final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly ?string $responseModel = null,
        public readonly array $options = [],
    ) {}
}
```

```php
// src/Router.php — add() gains options
public function add(string $method, string $path, callable $handler, ?string $responseModel = null, array $options = []): void
{
    $this->routes[] = new Route($method, $path, $handler, $responseModel, $options);
}
```

```php
// src/App.php — pipeline + options
use Falco\Http\MiddlewarePipeline;
use Falco\Http\MiddlewareInterface;

/** @var (MiddlewareInterface|callable)[] */
private array $middleware = [];

public function middleware(MiddlewareInterface|callable $middleware): void
{
    $this->middleware[] = $middleware;
}

public function get(string $path, callable $handler, ?string $responseModel = null, array $options = []): void
{ $this->router->add('GET', $path, $handler, $responseModel, $options); }
// ... same for post/put/patch/delete

public function handle(Request $request): Response
{
    $terminal = fn (Request $r): Response => $this->dispatch($r);
    $pipeline = new MiddlewarePipeline($this->middleware, $terminal);
    return $pipeline->handle($request);
}

private function dispatch(Request $request): Response
{
    $match = $this->router->match($request->method, $request->path);
    if ($match === null) {
        return Response::json(['detail' => 'Not Found'], 404);
    }
    $perRoute = $match->route->options['middleware'] ?? [];
    $terminal = fn (Request $r): Response => $this->invokeHandler($match, $r);
    if ($perRoute) {
        $inner = new MiddlewarePipeline($perRoute, $terminal);
        return $inner->handle($request);
    }
    return $terminal($request);
}

private function invokeHandler(\Falco\RouteMatch $match, Request $request): Response
{
    try {
        $args = $this->resolver->resolve($match->route->handler, $request, $match->pathParams);
        $result = ($match->route->handler)(...$args);
    } catch (ValidationException $e) {
        return Response::json(['detail' => $e->errors], 422);
    } catch (HttpException $e) {
        return Response::json(['detail' => $e->getMessage()], $e->statusCode);
    } catch (\Throwable $e) {
        return Response::json(['detail' => $this->debug ? $e->getMessage() : 'Internal Server Error'], 500);
    }
    if ($result instanceof Response) return $result;
    if ($result instanceof Model) $result = array_filter($result->toArray(), fn ($v) => $v !== null);
    return Response::json($result);
}
```

> Note: keep the error mapping in `invokeHandler` (App still owns it). Middleware added via `App::middleware()` wraps the whole pipeline; per-route `options['middleware']` wraps only that route. The spec's `['auth' => bool]` sugar is intentionally implemented as `['middleware' => [new AuthMiddleware($jwt, required: true)]]` (Task 6) — strictly more general, no extra foo.

- [ ] **Step 6: Run full suite**

Run: `php vendor/bin/phpunit`
Expected: `OK (29 tests, ...)` — legacy tests must still pass (Request/Response signatures backward-compatible).

- [ ] **Step 7: Commit**

```bash
git add . && git commit -m "feat: request/response extensions + middleware pipeline core"
```

---
### Task 2: Config + logging

**Files:**
- Create: `src/Config/Config.php`, `src/Logging/LoggerInterface.php`, `src/Logging/Logger.php`
- Test: `tests/ConfigTest.php`, `tests/LoggingTest.php`

**Interfaces:**
- Consumes: nothing outside stdlib.
- Produces: `Config::__construct(array $values)`, `Config::get(string $key, mixed $default = null): mixed`, `Logger::__construct(mixed $stream = STDOUT, string $minLevel = 'info')`, `Logger::info(string $msg, array $context = []): void`, `Logger::error(...)`.

- [ ] **Step 1: Write failing tests**

```php
// tests/ConfigTest.php
namespace Falco\Tests;

use Falco\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testGetReturnsDefault(): void
    {
        $c = new Config(['a' => 1]);
        $this->assertSame(1, $c->get('a'));
        $this->assertNull($c->get('missing'));
        $this->assertSame(2, $c->get('missing', 2));
    }
}
```

```php
// tests/LoggingTest.php
namespace Falco\Tests;

use Falco\Logging\Logger;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testEmitsJsonLine(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream);
        $log->info('hello', ['code' => 7]);
        rewind($stream);
        $line = json_decode(stream_get_contents($stream), true);
        $this->assertSame('info', $line['level']);
        $this->assertSame('hello', $line['message']);
        $this->assertSame(7, $line['code']);
    }

    public function testFiltersByLevel(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream, 'error');
        $log->info('noise');
        $log->error('boom');
        rewind($stream);
        $this->assertSame('boom', json_decode(stream_get_contents($stream), true)['message']);
    }

    public function testStringifiesNonScalars(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream);
        $log->info('ctx', ['obj' => (object)['x' => 1]]);
        rewind($stream);
        $this->assertSame(['x' => 1], json_decode(stream_get_contents($stream), true)['obj']);
    }
}
```

- [ ] **Step 2: Run to verify FAIL**

Run: `php vendor/bin/phpunit tests/ConfigTest.php tests/LoggingTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

```php
// src/Config/Config.php
<?php
namespace Falco\Config;

final class Config
{
    public function __construct(private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function fromEnv(string $prefix): self { return $this; } // reserved
}
```

```php
// src/Logging/LoggerInterface.php
<?php
namespace Falco\Logging;

interface LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
```

```php
// src/Logging/Logger.php
<?php
namespace Falco\Logging;

final class Logger implements LoggerInterface
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'error' => 400, 'critical' => 500];

    public function __construct(
        private $stream = STDOUT,
        private string $minLevel = 'info',
    ) {}

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 200) < self::LEVELS[$this->minLevel]) return;
        $record = [
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'message' => $message,
        ];
        foreach ($context as $k => $v) {
            $record[$k] = $this->stringify($v);
        }
        fwrite($this->stream, json_encode($record) . "\n");
    }

    private function stringify(mixed $v): mixed
    {
        if (is_scalar($v) || $v === null) return $v;
        if (is_array($v)) return array_map($this->stringify(...), $v);
        if ($v instanceof \JsonSerializable) return $v->jsonSerialize();
        if ($v instanceof \Stringable) return (string) $v;
        if (is_object($v)) return json_decode(json_encode($v), true);
        return (string) $v;
    }

    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }
}
```

- [ ] **Step 4: Run to verify PASS**

Run: `php vendor/bin/phpunit tests/ConfigTest.php tests/LoggingTest.php`
Expected: PASS (all 4).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: config + json structured logger"
```

---
### Task 3: Middleware setup — RequestId, ErrorHandler, RequestLogging

**Files:**
- Create: `src/Middleware/RequestIdMiddleware.php`, `src/Middleware/ErrorHandlerMiddleware.php`, `src/Middleware/RequestLoggingMiddleware.php`
- Test: `tests/MiddlewareTest.php`

**Interfaces:**
- Consumes: `MiddlewareInterface`, `Request`, `Response`, `Logger` (Task 2), existing `HttpException`, `ValidationException`.
- Produces: `RequestIdMiddleware` (sets response header `X-Request-Id`; reads allowed existing header), `ErrorHandlerMiddleware` constructor `(bool $debug = false)`, `RequestLoggingMiddleware::__construct(LoggerInterface $logger)`.

- [ ] **Step 1: Write failing tests**

```php
// tests/MiddlewareTest.php
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
    private function run(callable $mw, Request $req): Response
    {
        $pipeline = new MiddlewarePipeline([$mw], fn (Request $r): Response => new Response(200, [], ['ok' => true]));
        return $pipeline->handle($req);
    }

    public function testRequestIdSetsHeaderAndEchoes(): void
    {
        $res = $this->run(new RequestIdMiddleware(), new Request('GET', '/', [], [], []));
        $this->assertSame(1, preg_match('/^[0-9a-f-]{36}$/', $res->headers['x-request-id']));
    }

    public function testRequestIdAcceptsValidHeader(): void
    {
        $res = $this->run(new RequestIdMiddleware(), new Request('GET', '/', [], ['x-request-id' => 'abc-123'], []));
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
        $res = $this->run(new RequestLoggingMiddleware($log), new Request('GET', '/a', [], [], []));
        rewind($stream);
        $line = json_decode(stream_get_contents($stream), true);
        $this->assertSame('GET', $line['method']);
        $this->assertSame('/a', $line['path']);
        $this->assertSame(200, $line['status']);
        $this->assertArrayHasKey('duration_ms', $line);
    }
}
```

- [ ] **Step 2: Run to verify FAIL**

Run: `php vendor/bin/phpunit tests/MiddlewareTest.php`
Expected: FAIL (classes not found / imports wrong—fix `use` to `Falco\Middleware\...` per file).

- [ ] **Step 3: Implement — you will refine (the test as written uses `Middleware` in one use-line; adjust imports to the concrete classes)**

```php
<?php // src/Middleware/RequestIdMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $existing = $request->attributes['request_id'] ?? null;
        $validated = $existing !== null && preg_match('/^[A-Za-z0-9-_]{1,64}$/', (string) $existing)
            ? $existing : $this->uuid();  // or from header
        $res = $next($request->with('request_id', $validated));
        $res->headers['x-request-id'] = $validated;
        return $res;
    }

    private function generate(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
```

```php
<?php // src/Middleware/ErrorHandlerMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;
use Falco\HttpException;
use Falco\Validation\ValidationException;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug = false) {}

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ValidationException $e) {
            return Response::json(['detail' => $e->errors], 422);
        } catch (HttpException $e) {
            return Response::json(['detail' => $e->getMessage()], $e->statusCode);
        } catch (\Throwable $e) {
            return Response::json(['detail' => $this->debug ? $e->getMessage() : 'Internal Server Error'], 500);
        }
    }
}
```

```php
<?php // src/Middleware/RequestLoggingMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;
use Falco\Logging\LoggerInterface;

final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(Request $request, callable $next): Response
    {
        $start = microtime(true);
        $res = $next($request);
        $this->logger->info('request', [
            'method' => $request->method,
            'path' => $request->path,
            'status' => $res->status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'request_id' => $request->attributes['request_id'] ?? null,
        ]);
        return $res;
    }
}
```

- [ ] **Step 4: Run to verify PASS**

Run: `php vendor/bin/phpunit tests/MiddlewareTest.php`
Expected: PASS. Fix the test `use` line if the reviewer surfaces typehint mismatch (concrete classes only).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: request-id, error-handler, request-logging middleware"
```

---
### Task 4: CORS + security headers + rate limiting

**Files:**
- Create: `src/Middleware/CorsMiddleware.php`, `src/Middleware/SecurityHeadersMiddleware.php`, `src/Middleware/RateLimitStoreInterface.php`, `src/Middleware/InMemoryRateLimitStore.php`, `src/Middleware/RateLimitMiddleware.php`
- Test: extend `tests/MiddlewareTest.php`

**Interfaces:**
- Consumes: `MiddlewareInterface`, `Request`, `Response`.
- Produces: `CorsMiddleware::__construct(array $origins, array $methods = ['*'], ...)`, `SecurityHeadersMiddleware::__construct(bool $hsts = true)`, `RateLimitStoreInterface::increment(string $key, int $windowSec): int`, `InMemoryRateLimitStore`, `RateLimitMiddleware::__construct(RateLimitStoreInterface $store, int $max, int $windowSeconds)`.

- [ ] **Step 1: Write tests (append to `tests/MiddlewareTest.php`)**

```php
// tests/MiddlewareTest.php — append these methods inside the class (and add the imports below to the top of the file):
use Falco\Middleware\CorsMiddleware;
use Falco\Middleware\SecurityHeadersMiddleware;
use Falco\Middleware\InMemoryRateLimitStore;
use Falco\Middleware\RateLimitMiddleware;

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
}
```

- [ ] **Step 2: Run to verify FAIL**

Run: `php vendor/bin/phpunit tests/MiddlewareTest.php`

- [ ] **Step 3: Implement**

```php
<?php // src/Middleware/CorsMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $origins,
        private array $methods = ['*'],
        private array $headers = ['content-type', 'authorization'],
        private int $maxAge = 3600,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->headers['origin'] ?? null;
        $allowOrigin = $origin !== null && (in_array('*', $this->origins, true) || in_array($origin, $this->origins, true))
            ? $origin : null;
        if ($request->method === 'OPTIONS' && isset($request->headers['access-control-request-method'])) {
            $res = new Response(204, [
                'access-control-allow-origin' => $allowOrigin ?? '*',
                'access-control-allow-methods' => implode(', ', $this->methods),
                'access-control-allow-headers' => implode(', ', $this->headers),
                'access-control-max-age' => (string) $this->maxAge,
            ], '');
            return $res;
        }
        $res = $next($request);
        if ($allowOrigin !== null) $res->headers['access-control-allow-origin'] = $allowOrigin;
        return $res;
    }
}
```

```php
<?php // src/Middleware/SecurityHeadersMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $hsts = true) {}

    public function handle(Request $request, callable $next): Response
    {
        $res = $next($request);
        $res->headers['x-content-type-options'] = 'nosniff';
        $res->headers['x-frame-options'] = 'DENY';
        $res->headers['referrer-policy'] = 'strict-origin-when-cross-origin';
        if ($this->hsts) $res->headers['strict-transport-security'] = 'max-age=31536000';
        return $res;
    }
}
```

```php
<?php // src/Middleware/RateLimitStoreInterface.php
namespace Falco\Middleware;

interface RateLimitStoreInterface
{
    public function increment(string $key, int $windowSeconds): int;
}
```

```php
<?php // src/Middleware/InMemoryRateLimitStore.php
namespace Falco\Middleware;

final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    private array $hits = [];

    public function increment(string $key, int $windowSeconds): int
    {
        $now = time();
        $this->hits[$key] = array_values(array_filter(
            $this->hits[$key] ?? [],
            fn (int $t): bool => $t > $now - $windowSeconds,
        ));
        $this->hits[$key][] = $now;
        return count($this->hits[$key]);
    }
}
```

```php
<?php // src/Middleware/RateLimitMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimitStoreInterface $store,
        private int $maxRequests,
        private int $windowSeconds = 60,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $key = 'ip:' . ($request->ip ?: 'unknown');
        $count = $this->store->increment($key, $this->windowSeconds);
        if ($count > $this->maxRequests) {
            $res = Response::json(['detail' => 'Rate limit exceeded'], 429);
            $res->headers['retry-after'] = (string) $this->windowSeconds;
            $res->headers['x-rate-limit-remaining'] = '0';
            return $res;
        }
        $res = $next($request);
        $res->headers['x-rate-limit-remaining'] = (string) ($this->maxRequests - $count);
        return $res;
    }
}
```

- [ ] **Step 4: Run to verify PASS**

Run: `php vendor/bin/phpunit tests/MiddlewareTest.php`
Expected: all middleware tests PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: cors, security headers, rate-limit middleware"
```

---
### Task 5: JWT — service, claims, exceptions, auth store

**Files:**
- Create: `src/Security/JwtException.php`, `src/Security/JwtClaims.php`, `src/Security/JwtService.php`, `src/Security/RefreshTokenStoreInterface.php`
- Test: `tests/JwtTest.php`

**Interfaces:**
- Consumes: none beyond stdlib.
- Produces: `JwtService::encode(array $claims, int $ttlSeconds): string`, `JwtService::decode(string $token): array`, `RefreshTokenStoreInterface::issue(int $userId, int $ttlSeconds = 2592000): string`, `RefreshTokenStoreInterface::consume(string $token): ?int`, `RefreshTokenStoreInterface::revokeAll(int $userId): void`.

- [ ] **Step 1: Write failing tests**

```php
// tests/JwtTest.php
namespace Falco\Tests;

use Falco\Security\JwtService;
use Falco\Security\JwtException;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtService('0123456789abcdef0123456789abcdef');
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $token = $this->jwt->encode(['sub' => '42', 'role' => 'admin'], 60);
        $payload = $this->jwt->decode($token);
        $this->assertSame('42', $payload['sub']);
        $this->assertSame('admin', $payload['role']);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = $this->jwt->encode(['sub' => '1'], 60);
        $bad = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');
        $this->expectException(JwtException::class);
        $this->jwt->decode($bad);
    }

    public function testExpiredRejected(): void
    {
        $token = $this->jwt->encode(['sub' => '1'], -10);
        $this->expectException(JwtException::class);
        $this->jwt->decode($token);
    }

    public function testShortSecretRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtService('short');
    }
}
```

- [ ] **Step 2: Run to FAIL**

Run: `php vendor/bin/phpunit tests/JwtTest.php`

- [ ] **Step 3: Implement**

```php
<?php // src/Security/JwtException.php
namespace Falco\Security;

final class JwtException extends \RuntimeException {}
```

```php
<?php // src/Security/JwtClaims.php
namespace Falco\Security;

final class JwtClaims
{
    public function __construct(private array $claims) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->claims;
    }
}
```

```php
<?php // src/Security/JwtService.php
namespace Falco\Security;

final class JwtService
{
    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 bytes');
        }
        $this->secret = $secret;
    }

    public function encode(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $ttlSeconds]);
        $part = $this->b64(json_encode($header)) . '.' . $this->b64(json_encode($payload));
        return $part . '.' . $this->b64(hash_hmac('sha256', $part, $this->secret, true));
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new JwtException('invalid_token');
        [$head, $payload, $sig] = $parts;
        $expected = $this->b64(hash_hmac('sha256', $head . '.' . $payload, $this->secret, true));
        if (!hash_equals($expected, $sig)) throw new JwtException('invalid_signature');
        $claims = json_decode($this->unb64($payload), true);
        if (!is_array($claims)) throw new JwtException('invalid_token');
        if (($claims['exp'] ?? 0) < time()) throw new JwtException('expired');
        return $claims;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function unb64(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
```

```php
<?php // src/Security/RefreshTokenStoreInterface.php
namespace Falco\Security;

interface RefreshTokenStoreInterface
{
    public function issue(int $userId, int $ttlSeconds = 2592000): string;
    public function consume(string $token): ?int;
    public function revokeAll(int $userId): void;
}
```

- [ ] **Step 4: Run to verify PASS**

Run: `php vendor/bin/phpunit tests/JwtTest.php`
Expected: 4 tests pass. Note the tampered test relies on changing the last char changing the sig; if the flake equals coincidentally (1-in-64), adjust the last character deterministically (e.g. XOR with 1).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: jwt service with HS256 + refresh token interface"
```

---
### Task 6: AuthMiddleware + ParamResolver integration

**Files:**
- Create: `src/Middleware/AuthMiddleware.php`
- Modify: `src/Params/ParamResolver.php`, `tests/ParamResolverTest.php`
- Test: `tests/MiddlewareTest.php` (auth cases)

**Interfaces:**
- Consumes: `JwtService`, `JwtClaims`, `Request`, `Response`, `MiddlewareInterface`, `Path` replaced by variable (existing).
- Produces: `AuthMiddleware::__construct(JwtService $jwt, bool $required = true)`; unauthenticated → 401 `{"detail":"Not authenticated"}`.

- [ ] **Step 1: Write tests (tests/MiddlewareTest.php, tests/ParamResolverTest.php)**

```php
use Falco\Middleware\AuthMiddleware;
use Falco\Security\JwtService;

    public function testAuthRejectsMissingToken(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $mw = new AuthMiddleware($jwt);
        $res = $this->run($mw, new Request('GET', '/', [], [], []));
        $this->assertSame(401, $res->status);
    }

    public function testAuthAcceptsValidToken(): void
    {
        $jwt = new JwtService('0123456789abcdef0123456789abcdef');
        $token = $jwt->encode(['sub' => '7'], 60);
        $req = new Request('GET', '/', [], ['authorization' => "Bearer $token"], []);
        $res = $this->run(new AuthMiddleware($jwt), $req);
        $this->assertSame(200, $res->status);
    }
```
And in ParamResolverTest:

```php
use Falco\Security\JwtClaims;

public function testResolvesJwtClaims(): void
{
    $resolver = new ParamResolver();
    $req = (new \Falco\Request('GET', '/', [], [], []))->with('user', new JwtClaims(['sub' => '42']));
    $handler = function (JwtClaims $c): string { return $c->get('sub'); };
    $this->assertSame('42', $resolver->resolve($handler, $req, [])['c']->get('sub'));
}
```

- [ ] **Step 2: Run to FAIL**

Run: `php vendor/bin/phpunit tests/MiddlewareTest.php tests/ParamResolverTest.php`

- [ ] **Step 3: Implement**

```php
<?php // src/Middleware/AuthMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;
use Falco\Security\JwtService;
use Falco\Security\JwtClaims;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private JwtService $jwt, private bool $required = false) {}

    public function handle(Request $request, callable $next): Response
    {
        $auth = null;
        foreach ($request->headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $auth = $v; break; }
        }
        $token = $auth !== null && preg_match('/^Bearer\s+(.+)$/i', (string) $auth, $m) ? $m[1] : null;
        if ($token === null) {
            return $this->required ? Response::json(['detail' => 'Not authenticated'], 401) : $next($request);
        }
        try {
            $claims = new JwtClaims($this->jwt->decode($token));
        } catch (\Throwable $e) {
            return $this->required ? Response::json(['detail' => 'Not authenticated'], 401) : $next($request);
        }
        return $next($request->with('user', $claims));
    }
}
```

In `ParamResolver::resolveParam`, after the `Depends` check and before the `Request/Response` check, add:

```php
use Falco\HttpException;
use Falco\Security\JwtClaims;
```
(near the top of `ParamResolver.php`)

```php
if ($typeName === JwtClaims::class) {
    $claims = $request->attributes['user'] ?? null;
    if (!$claims instanceof JwtClaims) throw new HttpException(401, 'Not authenticated');
    return $claims;
}
```
(Note: combine with the existing `if ($type instanceof \ReflectionNamedType)` block; `$typeName` is defined there.)

- [ ] **Step 4: Run to verify PASS**

Run: `php vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: jwt auth middleware + param-resolved jwt claims"
```

---
### Task 7: Health + metrics

**Files:**
- Create: `src/Health/HealthController.php`, `src/Metrics/Registry.php`, `src/Metrics/Counter.php`, `src/Metrics/Histogram.php`, `src/Metrics/PrometheusTextFormatter.php`, `src/Metrics/MetricsMiddleware.php`
- Test: `tests/HealthTest.php`, `tests/MetricsTest.php`

**Interfaces:**
- Consumes: `App` (for route registration), `Request`, `Response`, `Connection` (Task 8 dependency — but health ready-check uses a plain callable to avoid ordering; production defines DI later).
- Produces: `HealthController::register(App $app, array $checks = [])` → adds `/health/live`, `/health/ready`; `Registry::counter(string $name, string $help): self`, `Counter::inc(string $method = '', string $path = '', int $status = 200)`, `Histogram::observe(float $seconds, string $method = '', string $path = '')`, `PrometheusTextFormatter::format(array $buckets): string`; `MetricsMiddleware::__construct(Metrics\Registry $registry)`.

- [ ] **Step 1: Write failing tests**

```php
// tests/HealthTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\Health\HealthController;
use Falco\Request;
use PHPUnit\Framework\TestCase;

final class HealthTest extends TestCase
{
    public function testLiveness(): void
    {
        $app = new App(docs: false);
        HealthController::register($app, []);
        $res = $app->handle(new Request('GET', '/health/live', [], [], []));
        $this->assertSame(200, $res->status);
    }

    public function testReadinessOk(): void
    {
        $app = new App(docs: false);
        HealthController::register($app, [fn () => true]);
        $res = $app->handle(new Request('GET', '/health/ready', [], [], []));
        $this->assertSame(200, $res->status);
    }

    public function testReadinessFails(): void
    {
        $app = new App(docs: false);
        HealthController::register($app, ['db' => fn () => false]);
        $res = $app->handle(new Request('GET', '/health/ready', [], [], []));
        $this->assertSame(503, $res->status);
    }
}
```

```php
// tests/MetricsTest.php
namespace Falco\Tests;

use Falco\Metrics\Registry;
use Falco\Metrics\Histogram;
use Falco\Metrics\PrometheusTextFormatter;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function testExportsText(): void
    {
        $registry = new Registry();
        $registry->counter('requests_total', 'total')->inc();
        $txt = (new PrometheusTextFormatter())->format($registry);
        $this->assertStringContainsString('# TYPE requests_total counter', $txt);
        $this->assertStringContainsString('requests_total 1', $txt);
    }
}
```

- [ ] **Step 2: Run to FAIL**

- [ ] **Step 3: Implement**

```php
<?php // src/Health/HealthController.php
namespace Falco\Health;

use Falco\App;
use Falco\Request;
use Falco\Response;

final class HealthController
{
    /** @param array<string, callable(): bool> $checks */
    public static function register(App $app, array $checks = []): void
    {
        $app->get('/health/live', fn (): array => ['status' => 'ok']);
        $app->get('/health/ready', function () use ($checks): array {
            $failed = [];
            foreach ($checks as $name => $fn) {
                try { if ($fn() !== true) $failed[] = $name; }
                catch (\Throwable) { $failed[] = $name; }
            }
            if ($failed) throw new \Falco\HttpException(503, 'Not ready: ' . implode(', ', $failed));
            return ['status' => 'ok'];
        });
    }
}
```

```php
<?php // src/Metrics/Counter.php
namespace Falco\Metrics;

final class Counter
{
    private array $values = [];
    private float $total = 0;

    public function __construct(private string $name, private string $help) {}

    public function inc(array $labels = []): void
    {
        $this->total += 1;
        $key = $this->key($labels);
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;
    }

    public function labelKeys(): array { return array_keys($this->values); }
    public function labelsFor(string $key): array { /* decode */ }
    public function total(): float { return $this->total; }
    public function name(): string { return $this->name; }
    public function help(): string { return $this->help; }
    private function key(array $labels): string { ksort($labels); return json_encode($labels); }
}
```

For histogram use the same `name/help` + `observe(float)` adding a fixed bucket set `[.005,.01,.025,.05,.1,.25,.5,1,2.5,5]`, tracking `sum`, `count`, per-bucket counts. Exporter emits:

```
# HELP falco_http_requests_total total
# TYPE falco_http_requests_total counter
falco_http_requests_total 1
# HELP falco_http_request_duration_seconds latency
# TYPE falco_http_request_duration_seconds histogram
falco_http_request_duration_seconds_bucket{le="0.005"} 0
...
falco_http_request_duration_seconds_sum 0.12
falco_http_request_duration_seconds_count 5
```

```php
<?php // src/Metrics/Registry.php
namespace Falco\Metrics;

final class Registry
{
    private array $metrics = [];

    public function counter(string $name, string $help): Counter
    {
        $m = new Counter($name, $help);
        $this->metrics[] = $m;
        return $m;
    }

    public function histogram(string $name, string $help): Histogram
    {
        $m = new Histogram($name, $help);
        $this->metrics[] = $m;
        return $m;
    }

    /** @return (Counter|Histogram)[] */
    public function all(): array { return $this->metrics; }
}
```

Implement `Histogram` and `PrometheusTextFormatter` (see above; `%` labels printed as `{}`).

```php
<?php // src/Metrics/MetricsMiddleware.php
namespace Falco\Metrics;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(private Registry $registry) {}

    public function handle(Request $request, callable $next): Response
    {
        $counter = $this->registry->counter('falco_http_requests_total', 'total requests');
        $hist = $this->registry->histogram('falco_http_request_duration_seconds', 'request latency');
        $start = microtime(true);
        $res = $next($request);
        $counter->inc(['method' => $request->method, 'status' => (string) $res->status]);
        $hist->observe(round(microtime(true) - $start, 6), ['method' => $request->method]);
        return $res;
    }
}
```

- [ ] **Step 4: Run; ensure PASS**

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: health endpoints + prometheus-style metrics"
```

---
### Task 8: Data layer; example app rewritten (+ auth flows)

**Files:**
- Create: `src/Data/Connection.php`, `src/Data/RefreshTokenRepository.php`, `examples/items/migrations/001_init.sql`
- Modify: `examples/items/app.php`
- Test: `tests/DataTest.php`, `tests/IntegrationTest.php`

**Interfaces:**
- Consumes: `Connection` (this task), `JwtService`, `RefreshTokenStoreInterface`, `App`, middleware from Tasks 1-6.
- Produces: `Connection::fromDsn(string $dsn, string $user = '', string $pass = ''): self`, `Connection::pdo(): \PDO`, `Connection::query(string $sql, array $params = []): \PDOStatement`, `Connection::exec(string \ sql, array $params = []): int`, `RefreshTokenRepository` (implements `RefreshTokenStoreInterface`).

- [ ] **Step 1: Write failing tests**

```php
// tests/DataTest.php
namespace Falco\Tests;

use Falco\Data\Connection;
use Falco\Data\RefreshTokenRepository;
use PHPUnit\Framework\TestCase;

final class DataTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = new Connection('sqlite::memory:');
        $this->conn->exec('CREATE TABLE refresh_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            consumed_at INTEGER NULL
        )');
    }

    public function testQuery(): void
    {
        $this->conn->exec('INSERT INTO refresh_tokens (token_hash, user_id, expires_at, consumed_at) VALUES (?,?,?,NULL)', ['h', 1, time() + 100]);
        $count = $this->conn->query('SELECT COUNT(*) AS n FROM refresh_tokens')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $count['n']);
    }

    public function testIssueAndConsume(): void
    {
        $repo = new RefreshTokenRepository($this->conn);
        $token = $repo->issue(5);
        $this->assertSame(5, $repo->consume($token));
        $this->assertNull($repo->consume($token)); // replay rejected
    }

    public function testRevokeAll(): void
    {
        $repo = new RefreshTokenRepository($this->conn);
        $t1 = $repo->issue(3);
        $repo->issue(3);
        $repo->revokeAll(3);
        $this->assertNull($repo->consume($t1));
    }
}
```

- [ ] **Step 2: Run to FAIL**

- [ ] **Step 3: Implement**

```php
<?php // src/Data/Connection.php
namespace Falco\Data;

final class Connection
{
    private \PDO $pdo;

    public function __construct(string $dsn, string $user = '', string $pass = '', array $options = [])
    {
        $defaults = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
        $this->pdo = new \PDO($dsn, $user, $pass, $defaults + $options);
    }

    public static function fromDsn(string $dsn, string $user = '', string $pass = ''): self
    {
        return new self($dsn, $user, $pass);
    }

    public function pdo(): \PDO { return $this->pdo; }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
```

```php
<?php // src/Data/RefreshTokenRepository.php
namespace Falco\Data;

use Falco\Security\RefreshTokenStoreInterface;

final class RefreshTokenRepository implements RefreshTokenStoreInterface
{
    public function __construct(private Connection $conn) {}

    public function issue(int $userId, int $ttlSeconds = 2592000): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->conn->exec(
            'INSERT INTO refresh_tokens (token_hash, user_id, expires_at, consumed_at) VALUES (?, ?, ?, NULL)',
            [hash('sha256', $token), $userId, time() + $ttlSeconds],
        );
        return $token;
    }

    public function consume(string $token): ?int
    {
        $hash = hash('sha256', $token);
        $row = $this->conn->query('SELECT user_id, consumed_at FROM refresh_tokens WHERE token_hash = ?', [$hash])->fetch();
        if (!$row || $row['consumed_at'] !== null) return null;
        $this->conn->exec('UPDATE refresh_tokens SET consumed_at = ? WHERE token_hash = ?', [time(), $hash]);
        return (int) $row['user_id'];
    }

    public function revokeAll(int $userId): void
    {
        $this->conn->exec('UPDATE refresh_tokens SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL', [time(), $userId]);
    }
}
```

`examples/items/migrations/001_init.sql`:

```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS refresh_tokens (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    expires_at INTEGER NOT NULL,
    consumed_at INTEGER NULL
);
CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    name TEXT NOT NULL,
    price REAL NOT NULL,
    created_at INTEGER NOT NULL
);
```

- [ ] **Step 4: Run; PASS**

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: PDO connection + refresh-token repository + migrations"
```

---
### Task 9: Rewrite example app with auth flows + full integration tests

**Files:**
- Modify: `examples/items/app.php`, `tests/IntegrationTest.php`
- Create: `.env.example`

**Interfaces:**
- Consumes: everything Tasks 1-8.
- Produces: executable example app exercising login/refresh/items + wiring.

- [ ] **Step 1: Write failing integration test**

```php
// tests/IntegrationTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\Request;
use Falco\Response;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FALCO_JWT_SECRET=0123456789abcdef0123456789abcdef');
        putenv('FALCO_SEED_PASSWORD=pass');
        putenv('FALCO_SQLITE_PATH=' . sys_get_temp_dir() . '/falco_it_' . bin2hex(random_bytes(4)) . '.sqlite');
    }

    protected function boot(): App
    {
        $app = require dirname(__DIR__) . '/examples/items/app.php';
        return $app;
    }

    public function testLoginFlow(): void
    {
        $app = $this->boot();
        $req = new Request('POST', '/login', [], ['content-type' => 'application/json'], ['username' => 'admin', 'password' => 'pass']);
        $res = $app->handle($req);
        $this->assertSame(200, $res->status);
        $this->assertArrayHasKey('access_token', $res->body);
        $this->assertArrayHasKey('refresh_token', $res->body);
    }

    public function testProtectedRouteRequiresAuth(): void
    {
        $app = $this->boot();
        $res = $app->handle(new Request('GET', '/items', [], [], []));
        $this->assertSame(401, $res->status);
    }

    public function testRefreshRotates(): void
    {
        $app = $this->boot();
        $login = $app->handle(new Request('POST', '/login', [], [], ['username' => 'admin', 'password' => 'pass']));
        $old = $login->body['refresh_token'];
        $refresh = $app->handle(new Request('POST', '/refresh', [], [], ['refresh_token' => $old]));
        $this->assertSame(200, $refresh->status);
        // replay rejected
        $replay = $app->handle(new Request('POST', '/refresh', [], [], ['refresh_token' => $old]));
        $this->assertSame(401, $replay->status);
    }
}
```

- [ ] **Step 2: Run to FAIL (no example app redirect yet)**

- [ ] **Step 3: Implement. `examples/items/app.php` wiring (rewrite full file)**

```php
<?php // examples/items/app.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Falco\App;
use Falco\Config\Config;
use Falco\Data\Connection;
use Falco\Data\RefreshTokenRepository;
use Falco\Health\HealthController;
use Falco\HttpException;
use Falco\Logging\Logger;
use Falco\Metrics\MetricsMiddleware;
use Falco\Metrics\PrometheusTextFormatter;
use Falco\Metrics\Registry;
use Falco\Middleware\AuthMiddleware;
use Falco\Middleware\CorsMiddleware;
use Falco\Middleware\ErrorHandlerMiddleware;
use Falco\Middleware\RequestIdMiddleware;
use Falco\Middleware\RequestLoggingMiddleware;
use Falco\Middleware\SecurityHeadersMiddleware;
use Falco\Params\Depends;
use Falco\Response;
use Falco\Security\JwtService;

$cfg = new Config([
    'jwt_secret' => (string) (getenv('FALCO_JWT_SECRET') ?: ''),
    'metrics' => (string) (getenv('FALCO_METRICS') ?: '0'),
    'cors_origins' => array_filter(array_map('trim', explode(',', (string) (getenv('FALCO_CORS_ORIGINS') ?: '')))),
    'sqlite_path' => (string) (getenv('FALCO_SQLITE_PATH') ?: __DIR__ . '/data.sqlite'),
]);

if (strlen($cfg->get('jwt_secret')) < 32) {
    throw new \RuntimeException('FALCO_JWT_SECRET must be at least 32 chars');
}
$db = new Connection('sqlite:' . $cfg->get('sqlite_path'));
$db->pdo()->exec(file_get_contents(__DIR__ . '/migrations/001_init.sql'));

$logger = new Logger();
$jwt = new JwtService($cfg->get('jwt_secret'));
$store = new RefreshTokenRepository($db);
$app = new App(title: 'Items API', version: '1.0');

$app->middleware(new RequestIdMiddleware());
$app->middleware(new ErrorHandlerMiddleware());
$app->middleware(new SecurityHeadersMiddleware());
$origins = $cfg->get('cors_origins') ?: ['*'];
$app->middleware(new CorsMiddleware($origins));

// seed demo user (dev only if FALCO_SEED_PASSWORD set)
$seedPass = (string) (getenv('FALCO_SEED_PASSWORD') ?: '');
if ($seedPass !== '' && !$db->query('SELECT 1 FROM users WHERE username = ?', ['admin'])->fetch()) {
    $db->exec('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)',
        ['admin', password_hash($seedPass, PASSWORD_DEFAULT), time()]);
}

$app->post('/login', function (#[\Falco\Params\Body] array $body) use ($db, $jwt, $store): array {
    $username = (string) ($body['username'] ?? '');
    $password = (string) ($body['password'] ?? '');
    $row = $db->query('SELECT id, password_hash FROM users WHERE username = ?', [$username])->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        throw new HttpException(401, 'Incorrect username or password');
    }
    $userId = (int) $row['id'];
    return [
        'access_token' => $jwt->encode(['sub' => $userId], 900),
        'refresh_token' => $store->issue($userId),
        'token_type' => 'bearer',
    ];
});

$app->post('/refresh', function (#[\Falco\Params\Body] array $body) use ($store, $jwt): array {
    $userId = $store->consume((string) ($body['refresh_token'] ?? ''));
    if ($userId === null) throw new HttpException(401, 'Invalid refresh token');
    return [
        'access_token' => $jwt->encode(['sub' => $userId], 900),
        'refresh_token' => $store->issue($userId),
        'token_type' => 'bearer',
    ];
});

$auth = new AuthMiddleware($jwt, required: true);

$app->post('/items', function (#[\Falco\Params\Body] array $body, \Falco\Security\JwtClaims $user) use ($db): array {
    $db->exec(
        'INSERT INTO items (user_id, name, price, created_at) VALUES (?, ?, ?, ?)',
        [(int) $user->get('sub'), (string) $body['name'], (float) $body['price'], time()],
    );
    $id = (int) $db->pdo()->lastInsertId();
    return ['id' => $id, 'name' => $body['name'], 'price' => (float) $body['price']];
}, null, ['middleware' => [$auth]]);

$app->get('/items', function (\Falco\Security\JwtClaims $user) use ($db): array {
    return array_map(function (array $row): array {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'price' => (float) $row['price']];
    }, $db->query('SELECT id, name, price FROM items WHERE user_id = ?', [(int) $user->get('sub')])->fetchAll());
}, null, ['middleware' => [$auth]]);

$app->get('/items/{item_id}', function (\Falco\Security\JwtClaims $user, int $item_id) use ($db): array {
    $row = $db->query('SELECT id, name, price FROM items WHERE id = ? AND user_id = ?', [$item_id, (int) $user->get('sub')])->fetch();
    if (!$row) throw new HttpException(404, 'Item not found');
    return ['id' => (int) $row['id'], 'name' => $row['name'], 'price' => (float) $row['price']];
}, null, ['middleware' => [$auth]]);

$app->delete('/items/{item_id}', function (\Falco\Security\JwtClaims $user, int $item_id) use ($db): array {
    $n = $db->exec('DELETE FROM items WHERE id = ? AND user_id = ?', [$item_id, (int) $user->get('sub')]);
    if ($n === 0) throw new HttpException(404, 'Item not found');
    return ['ok' => true];
}, null, ['middleware' => [$auth]]);

if ($cfg->get('metrics') === '1') {
    $registry = new Registry();
    $app->middleware(new MetricsMiddleware($registry));
    $app->get('/metrics', fn (): Response => Response::text((new PrometheusTextFormatter())->format($registry)));
}

HealthController::register($app, ['db' => function () use ($db): bool {
    $db->query('SELECT 1');
    return true;
}]);

return $app;
```

`.env.example` (repo root):

```
FALCO_JWT_SECRET=change-me-to-a-long-random-string-32+
FALCO_SQLITE_PATH=./data/app.sqlite
FALCO_CORS_ORIGINS=https://app.example.com
FALCO_METRICS=1
FALCO_SEED_PASSWORD=pass
```

Run: `php -l examples/items/app.php`

- [ ] **Step 4: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: ALL tests green (existing tests + DataTest + IntegrationTest).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: sqlite-backed example app with jwt auth flows"
```

---
### Task 10: README + production docs + final verification

**Files:**
- Create: `README` section update, `docs/PRODUCTION.md`
- Modify: `README.md`

**Consumes:** all tasks.

- [ ] Step 1: Add `docs/PRODUCTION.md` with:
  - Env vars table (JWT secret, SQLite path, CORS origins, metrics toggle, seed creds)
  - Run behind nginx/php-fpm; Swoole behind proxy; HSTS via proxy; CORS allowlist; rate-limiting defaults; JWT rotation; SQLite backups
  - Zero-dep note
- [ ] Step 2: Update README Features + a new "Production" section w/ link to PRODUCTION.md; update example description
- [ ] Step 3: Full verification: `php vendor/bin/phpunit` (expect ~45 tests green), `php bin/falco serve examples/items/app.php` boot smoke, then curl:
  - `curl -s localhost:8000/health/ready` → 200
  - `curl -s -X POST localhost:8000/login -H 'Content-Type: application/json' -d '{"username":"admin","password":"pass"}'` → tokens
- [ ] Step 4: `git add -A && git commit -m "docs: production guide + readme update"`
- [ ] Step 5: Final review gate: `php vendor/bin/phpunit` + `git status` clean.

---
## Self-Review Acknowledgment

Check at end that: every spec §3-§5 feature maps to a task above; all signatures consistent (`MiddlewareInterface`, `LoggerInterface`, `JwtService`, `RefreshTokenStoreInterface`, `Connection`, `Request::with`, `Response::text`); no placeholder left in code snippets; each task is independently testable and committed.
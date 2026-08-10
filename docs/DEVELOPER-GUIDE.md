# Falco Developer Guide

A top-to-bottom guide to building, extending, and running an app with Falco.
Read it alongside the source; every concept maps to a single file under `src/`.

## 1. Big picture

Falco is a **thin layer** over PHP's built-in server (or Swoole, or php-fpm).
There is:

- no service container
- no annotations to maintain for routing (routes are method calls)
- no runtime dependencies (just the PHP standard library + the PSR-4 autoloader
  `vendor/autoload.php`, which Composer generates for dev)
- one public entry point: `App`

A complete app is a file that **returns an `App`**:

```php
// app.php
require 'vendor/autoload.php';
use Falco\App;
$app = new App(title: 'My API', version: '1.0.0');
$app->get('/', fn() => ['ok' => true]);
return $app;
```

Run it: `php bin/falco serve app.php`. The CLI spins up PHP's built-in server
and exposes the app via the router `bin/server.php`.

## 2. Application bootstrap

```php
use Falco\App;

$app = new App(
    title: 'Items API',
    version: '1.0.0',
    docs: true,    // registers /openapi.json + /docs (Swagger UI)
    debug: false,  // when true, 500 responses include the exception message
);
```

`App` owns:

- a `Falco\Router` — the route table
- a `Falco\Params\ParamResolver` — argument binding
- a list of **global middleware**

Routes are registered with `$app->get/post/put/patch/delete($path, $handler,
$responseModel = null, $options = [])`. The `$options['middleware']` array holds
**per-route** middleware (e.g. auth). See `src/App.php`.

## 3. Handlers

A handler is any `callable` whose parameters are resolved by type/attribute
(see [Parameter resolution](#6-parameter-resolution)). You may return:

| Return value | Wire format |
|---|---|
| `Falco\Response` | sent verbatim (status + headers + body) |
| `Falco\Model` | `toArray()` with nulls stripped, JSON |
| `array` / `int` / `float` / `string` / `bool` | wrapped in JSON |

```php
$app->get('/hi/{name}', fn(string $name) => ['message' => "Hi, {$name}!"]);
```

## 4. Environment & configuration

Falco reads configuration from the process environment. The example app wraps it
in `Falco\Config\Config` (`src/Config/Config.php`):

```php
$cfg = new Config([
    'jwt_secret' => (string) getenv('FALCO_JWT_SECRET'),
    'sqlite_path' => (string) (getenv('FALCO_SQLITE_PATH') ?: __DIR__ . '/data/app.sqlite'),
    'cors_origins' => array_filter(explode(',', getenv('FALCO_CORS_ORIGINS') ?: '')),
    'metrics' => getenv('FALCO_METRICS') ?: '0',
    'debug' => (bool) (getenv('FALCO_DEBUG') ?: false),
    // ...see docs/PRODUCTION.md for the full variable list
]);
$cfg->get('key', $default);
```

On shared hosts that don't expose process env vars, supply values via
`examples/items/conf.php` → `env.php` (see [Deploy](#11-deploy)).

### Environment variables

| Variable | Required | Default | Notes |
|---|---|---|---|
| `FALCO_JWT_SECRET` | Yes | — | ≥ 32 random bytes: `php -r 'echo bin2hex(random_bytes(32));'` |
| `FALCO_SQLITE_PATH` | No | `data/app.sqlite` | Writable directory; the DB file is created on first request. |
| `FALCO_CORS_ORIGINS` | No | empty = deny | Comma-separated whitelist; `''` → no `allow-origin` header. |
| `FALCO_CORS_METHODS` | No | `*` | Allowed methods for preflight. |
| `FALCO_METRICS` | No | `0` | `1` exposes `/metrics`. |
| `FALCO_DEBUG` | No | `0` | `1` surfaces exceptions in 500 bodies (dev only). |
| `FALCO_RATE_LIMIT` | No | `100` | Max requests per window per IP. |
| `FALCO_RATE_WINDOW` | No | `60` | Window length in seconds. |
| `FALCO_RATE_LIMIT_STORE` | No | `memory` | `memory` (per-process) or `sqlite`. |
| `FALCO_SEED_PASSWORD` | No | — | Seeds an `admin` user on startup (dev convenience). |
| `FALCO_SECURITY_HSTS` | No | `1` | `0` disables HSTS (e.g. behind a TLS-terminating proxy). |

## 5. Routing

`src/Router.php` stores an ordered list of `Route` (`src/Route.php`) and matches
with a linear scan. Paths are compiled from a template into a single anchored
PCRE:

```php
$router->add('GET', '/items/{item_id}', $handler);
```

Rules:

- `{name}` → a named capture `(?P<name>[^/]+)` — a **single** path segment, so
  `/items/1/details` is never swallowed by `/items/{id}`.
- Trailing slashes (other than the root) are trimmed, so `/items/` hits `/items`.
- Registration order matters: routes are matched top-to-bottom.
- Path params arrive in `RouteMatch->pathParams` and are bound to handler
  parameters **by name**.

`Router::routes()` is also what the OpenAPI generator walks — there is no
second route registry.

## 6. Parameter resolution

`src/Params/ParamResolver.php` inspects a handler's parameters via reflection
and binds each one, in this order (first match wins):

1. **`#[Depends]`** → `DependencyContainer` (DI).
2. **Typed `Request` / `Response`** → framework singletons.
3. **`Model` subclass** → treated as `#[Body]` and validated.
4. **`JwtClaims`** → reads `request.attributes['user']` (set by `AuthMiddleware`);
   throws `HttpException(401)` if missing.
5. **Path parameter** (`RouteMatch->pathParams`) → coerced to the declared type.
6. **`#[Header($alias?)]`** → request header (case-insensitive).
7. **`#[Body($alias?)]`** → JSON body, coerced to the declared type.
8. **`#[Query($alias?)]` / bare scalar/array** → query-string value.

> A bare scalar parameter with no attribute is **query** by default — this is
> the FastAPI ergonomic. Use `#[Body]` (or a `Model`) to read the JSON body.

Attributes live in `src/Params/Body.php`, `Query.php`, `Header.php`, `Depends.php`.
Each accepts an optional `alias`.

### Dependency injection (`#[Depends]`)

```php
$app->get('/', function (#[Depends] Repo $repo): array { ... });
```

`Depends` may be a function name, a `[Class, '__invoke']` pair, or omit the
callable — then the parameter's own type is used (`Class::__invoke` or class
reflection). `src/Params/DependencyContainer.php` resolves and memoizes per
dependency. Constructor params of the dependency are built with default values
where available; required params without defaults throw `LogicException`.

## 7. Models & validation

`src/Model.php` is an abstract base. Subclasses declare **public typed
properties**; reflection drives coercion and serialization:

```php
use Falco\Model;
final class CreateItem extends Model {
    public string $name;
    public float $price;
    public ?string $note = null; // nullable -> optional, omitted from output if null
}
```

- `Model::fromArray($data)` coerces each property through
  `Validation\Validator::coerce()`; missing required fields throw
  `ValidationException` (`loc: ["body","name"]`, `msg`, `type`).
- `Model::toArray()` serializes public props recursively; handlers' `Model`
  returns are filtered through this.
- `Validator::coerce()` handles `int`/`float`/`string`/`bool`/`array`/`null`,
  `BackedEnum` (by value), `Model` (recursive), and **union types** (`int|string`
  tries each arm in order).

`SchemaBuilder` (`src/OpenAPI/SchemaBuilder.php`) mirrors this to render
OpenAPI schemas — no separate annotations needed.

## 8. Error handling

Two layers both map exceptions to JSON:

- `App::invokeHandler()` catches per handler (inside the pipeline).
- `Middleware\ErrorHandlerMiddleware` catches globally (wraps everything).

```
ValidationException  -> 422  {"detail":[{"loc":[...],"msg":"Field required","type":"missing"}]}
HttpException        -> <its status>  {"detail":"<message>"}
Throwable            -> 500  {"detail":"Internal Server Error"}  (or the message if debug=true)
```

Throw deliberately from anywhere:

```php
throw new \Falco\HttpException(401, 'Not authenticated');
```

## 9. Middleware

Contracts (`src/Http/MiddlewareInterface.php`):

```php
public function handle(Request $request, callable $next): Response;
```

The pipeline (`src/Http/MiddlewarePipeline.php`) is a recursive onion: each layer
gets a `$next` closure; exhausting the list calls the terminal. **Both class
middleware and plain callables are accepted.**

Two scopes:

- **Global** — `App::middleware($mw)`, registered once, wraps every request.
- **Per-route** — `options: ['middleware' => [$mw]]`, wraps only that route.

Built-ins (`src/Middleware/`):

| Class | What it does |
|---|---|
| `RequestIdMiddleware` | propagates/creates `x-request-id`; stamps response; stores on request attributes. |
| `RequestLoggingMiddleware` | one JSON log line per request (method, path, status, duration_ms, request_id). |
| `ErrorHandlerMiddleware` | maps exceptions → JSON (see [Errors](#8-error-handling)). |
| `AuthMiddleware` | `Authorization: Bearer <jwt>` → verifies + attaches `JwtClaims`. `required: bool`. |
| `CorsMiddleware` | preflight (204) + actual requests; `Vary: origin`; origin whitelist. |
| `SecurityHeadersMiddleware` | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and HSTS. |
| `RateLimitMiddleware` | per-IP sliding window; 429 + `Retry-After` on limit; `X-RateLimit-Remaining`. |
| `MetricsMiddleware` | records request count + latency into a `Metrics\Registry`. |

### Writing middleware

```php
use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class MyMiddleware implements MiddlewareInterface {
    public function handle(Request $request, callable $next): Response {
        // ...do something before...
        $res = $next($request);
        // ...do something to $res...
        return $res;
    }
}
```

Register globally: `$app->middleware(new MyMiddleware());`
Register per-route: `$app->get('/secret', $handler, options: ['middleware' => [new MyMiddleware()]])`.

## 10. Authentication (JWT + refresh)

`src/Security/JwtService.php` — HS256, `hash_equals`, `exp` check, base64url.

```php
$jwt = new \Falco\Security\JwtService($envSecret);   // >= 32 bytes
$token = $jwt->encode(['sub' => 1, 'username' => 'admin'], 900); // 15 min
$claims = $jwt->decode($token); // throws JwtException on bad/expired
```

`AuthMiddleware` (per-route) decodes the `Authorization: Bearer` header and
stores a `{see JwtClaims}` under request attribute `'user'`; the resolver reads
it for `JwtClaims`-typed params.

Refresh tokens (`Data/RefreshTokenRepository.php`):

- raw token = `random_bytes(32)`, base64url; only the **SHA-256 hash** is stored.
- `issue($userId)` → INSERT with `expires_at`.
- `consume($token)` → rejects if missing / already-`consumed_at` / expired;
  then marks `consumed_at`. Replaying a used token is a `401`.
- `revokeAll($userId)` → burns every live token for the user (used by `/logout`).

The example app's flow (`/login` → access+refresh → `/refresh` rotates both →
replay rejected → `/logout` revokes) is covered end-to-end by
`tests/IntegrationTest.php`.

## 11. Rate limiting

`RateLimitMiddleware` keys by `ip:<request ip>`, delegates storage to a
`RateLimitStoreInterface` (`src/Middleware/RateLimitStoreInterface.php`):

- `InMemoryRateLimitStore` — per-process sliding window (default; great for a
  single `php -S` or Swoole worker).
- `SqliteRateLimitStore` — persists windows in a SQLite table so the limit is
  enforced **across php-fpm workers** (use `FALCO_RATE_LIMIT_STORE=sqlite`).

Both `increment($key, $windowSeconds): int` and the window math are encapsulated;
swap your own backend by implementing the interface.

## 12. Data layer

`src/Data/Connection.php` is a small PDO wrapper — **no ORM**:

```php
$db = new Connection('sqlite:/path/app.sqlite');        // or 'pgsql:host=...' / 'mysql:...'
$db->query('SELECT * FROM items WHERE user_id = ?', [$uid])->fetchAll();
$db->exec('INSERT INTO items (user_id, name) VALUES (?, ?)', [$uid, $name]);
```

- `query()` → prepared + executed `PDOStatement` (default `FETCH_ASSOC`, exceptions on).
- `exec()` → affected row count.
- Any PDO DSN swaps the engine with zero code changes.

## 13. Observability

### Logging (`src/Logging/`)

PSR-3-ish `LoggerInterface`; `Logger` writes one JSON object per line (default
to STDOUT). Resilient: `json_encode` uses `JSON_INVALID_UTF8_SUBSTITUTE`, and
`JsonSerializable` context values are re-descended so a malformed object never
yields a blank line.

```php
use Falco\Logging\Logger;
$logger = new Logger();
$logger->info('request', ['method' => 'GET', 'path' => '/', 'request_id' => $id]);
```

`RequestLoggingMiddleware` emits these automatically (it carries the request id
from `RequestIdMiddleware`).

### Metrics (`src/Metrics/`)

`Counter` / `Histogram` (fixed buckets), held in a `Registry`.
`MetricsMiddleware` records:

- `falco_http_requests_total{method,status}`
- `falco_http_request_duration_seconds_bucket/...`

Expose them with `PrometheusTextFormatter`:

```php
$app->get('/metrics', fn() => Response::text((new PrometheusTextFormatter())->format($registry)));
```

(Toggle with `FALCO_METRICS=1` in the example app.)

### Health (`src/Health/`)

```php
use Falco\Health\HealthController;
HealthController::register($app, ['db' => fn() => $db->query('SELECT 1')->fetch()]);
```

- `GET /health/live` → always `200 {"status":"ok"}`.
- `GET /health/ready` → runs the check callbacks; any failure → `503 {"status":"failed","checks":[...]}`.

## 14. OpenAPI / docs

With `debug`/docs enabled (default), the app registers:

- `GET /openapi.json` → `OpenApiGenerator` walks `App::routes()` (no annotations),
  reflects handler params + `responseModel` `Model` classes, and emits an OpenAPI
  3.1 doc under `components.schemas`.
- `GET /docs` → Swagger UI (bundled from jsDelivr).

`SchemaBuilder` maps PHP types: scalars, nullable → `nullable`, `BackedEnum` →
`enum`, unions → `anyOf`, `Model` → `$ref`.

## 15. Runtimes

| Target | Command | Notes |
|---|---|---|
| Dev / built-in | `php bin/falco serve app.php` | `php -S`, single-threaded, sequential. Router = `bin/server.php` (app via `FALCO_APP`). |
| Swoole | `php bin/falco serve app.php --swoole` | `ext-swoole` required; long-lived (good for in-process state). |
| nginx + php-fpm | `public/index.php` or `bin/server.php` as router | Standard CGI deployment; stateless per request → prefer `SqliteRateLimitStore`. |
| Shared hosting | `examples/items/public/index.php` + `.htaccess` | Document root = `public/`; app kept outside root; env via `env.php`. |

`bin/falco` builds the `php -S` invocation and sets `FALCO_APP`; `bin/server.php`
is the actual router (require the app, `handle($request)->send()`, `return true`
so `php -S` falls through to a matching static file). For Swoole,
`Runtime/SwooleRuntime.php` wraps a `Swoole\Http\Server` and reuses `App::handle()`
per request — same dispatch, no code changes.

## 16. Testing

Falco is covered by unit + integration tests. Run:

```bash
php vendor/bin/phpunit              # 78 tests, 155 assertions
php vendor/bin/phpunit --testdox
```

What's tested:

- `RouterTest` — exact, template, no-match, trailing-slash.
- `ParamResolverTest` — query/body/path/header/model/Depends/JwtClaims resolution + missing-field 422.
- `MiddlewareTest` — CORS allow/deny, preflight, security headers, rate-limit tripping, auth accept/reject/invalid/optional.
- `MiddlewarePipelineTest` — ordering + empty-pipeline.
- `ModelTest`, `ValidatorTest` — coercion + FastAPI-style errors.
- `OpenApiTest` — schema + docs generation.
- `MetricsTest`, `HealthTest` — histogram labels, readiness 503 shape.
- `DataTest` — `Connection` + refresh-token issue/consume/replay/revocation.
- `JwtTest` — sign/verify/expiry/claims.
- `IntegrationTest` — boots `examples/items/app.php` over a temp SQLite DB and
  asserts the full **login → protected `/items` → refresh → rotate → replay
  rejected → logout** flow (status codes and bodies).

The example app is **not** loaded by unit tests except via `IntegrationTest`, which
uses an in-memory SQLite DB seeded per run.

### Writing tests

```php
use Falco\App;
use Falco\Request;
use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase {
    public function testHandler(): void {
        $app = new App(title: 'T', version: '1', docs: false, debug: true);
        $app->get('/hi/{name}', fn(string $name) => ['msg' => "hi $name"]);
        $res = $app->handle(new Request('GET', '/hi/alice', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertSame(['msg' => 'hi alice'], $res->body);
    }
}
```

`App::handle()` returns a `Response` directly — no server needed.

## 17. Project layout

```
FastAPI-PHP/
├── bin/
│   ├── falco            # CLI: `serve <app.php> [--swoole]`
│   └── server.php       # php -S router (reads FALCO_APP)
├── src/
│   ├── App.php          # public entry: routes, params, middleware, OpenAPI
│   ├── Router.php / Route.php / RouteMatch.php
│   ├── Request.php / Response.php / HttpException.php
│   ├── Params/          # ParamResolver + Depends/Query/Header/Body + container
│   ├── Validation/      # Validator (coercion) + ValidationException
│   ├── Model.php
│   ├── Config/
│   ├── Http/            # MiddlewareInterface + MiddlewarePipeline
│   ├── Middleware/      # the built-ins (RequestId, Logging, Error, Auth, CORS,
│   │                     # SecurityHeaders, RateLimit, InMemory/Sqlite stores)
│   ├── Security/        # JwtService, JwtClaims, JwtException, RefreshTokenStoreInterface
│   ├── Data/            # Connection (PDO) + RefreshTokenRepository
│   ├── Metrics/         # Counter, Histogram, Registry, MetricsMiddleware, Prometheus formatter
│   ├── Health/          # HealthController
│   ├── OpenAPI/         # OpenApiGenerator, SchemaBuilder, DocsController
│   ├── Logging/         # LoggerInterface, Logger
│   └── Runtime/         # SwooleRuntime
├── examples/items/      # production-style app (auth, health, metrics, CORS, rate limit)
│   ├── app.php          # routes + middleware wiring
│   ├── conf.php         # env bootstrap (getenv OR env.php)
│   ├── env.example.php  # copy -> env.php
│   ├── migrations/001_init.sql
│   └── public/          # shared-hosting front controller (index.php + .htaccess)
├── tests/
├── docs/
│   ├── PRODUCTION.md
│   └── DEVELOPER-GUIDE.md   # this file
├── composer.json
└── phpunit.xml
```

## 18. Frequently asked questions

**Do I need Composer in production?** No. `vendor/autoload.php` is a dev-time
convenience. Drop Falco on a host without Composer using the autoloader snippet
in [§2 bootstrap](#2-application-bootstrap) (Install).

**Can I plug my own database / storage?** Yes. `Data\Connection` accepts any PDO
DSN. For auth persistence, implement `Security\RefreshTokenStoreInterface`
and wire it where you build the `RefreshTokenRepository`.

**Can I add metrics beyond the defaults?** Yes. `Metrics\Registry` exposes
`counter($name, $help)` / `histogram($name, $help)`; read it from a handler and
format it with `PrometheusTextFormatter`.

**Is the built-in server production-ready?** No — single-threaded. Use
nginx+php-fpm (`public/index.php` or `bin/server.php`) or Swoole. For
multi-worker php-fpm, set `FALCO_RATE_LIMIT_STORE=sqlite`.

**Where are exceptions handled?** In `App::invokeHandler()` (per handler) **and**
redundantly in `ErrorHandlerMiddleware` (global), so errors raised before the
pipeline still return JSON.

**How are path params injected?** By **name**: a route `/items/{item_id}` binds
to a handler parameter named `$item_id` (order-independent).

## License

MIT.

# Falco Production-Ready Design Spec

Date: 2026-08-08
Status: Approved (brainstorming → design drawing)

## 1. Goal

Turn Falco (`falco/falco`, PHP 8.1+ web framework, zero runtime
deps) into a production-ready framework library plus a hardened example app.
Scope, confirmed with the user:

- **Framework features + hardened example only.** No standalone deployment
  kit (no Dockerfiles, no CI workflows).
- **One decomposed roadmap**: middleware pipeline → logging → metrics →
  security headers/CORS → JWT auth + refresh rotation → health checks →
  data layer → example wiring.
- **Zero runtime dependencies**, enforced. PHP stdlib only (`hash_hmac`,
  `random_bytes`, `json_decode`, PDO, `password_hash`). Composer remains
  autoload + PHPUnit (dev only).
- **Stays on Falco's own `Request`/`Response` value objects.** No PSR-7, no
  PSR-15, no framework deps.

## Target Architecture

Request flow through a middleware pipeline, then route dispatch:

```
Request
  RequestId       (uuid v4 or validated X-Request-Id; sets X-Request-Id)
  ErrorHandler    (Throwable -> JSON error response)
  RateLimit       (per IP and/or bearer token)
  Cors            (headers, preflight 204)
  SecurityHeaders (CSP/HSTS/nosniff defaults; off-by-default)
  Auth            (only when a route requires it)
  RequestLogging  (success line: method, path, status, duration, request_id)
  Route dispatch  (first-match route via Router)

Response passes back through the same layers (unwinding the onion).
```

`App::handle()` composes the full pipeline per request from global middleware
plus the matched route's per-route middleware.

## Middleware Foundation — `Falco\Http`

### `MiddlewareInterface`
```php
namespace Falco\Http;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
```

### `MiddlewarePipeline`
- Holds an ordered list of middleware plus a terminal dispatch closure.
- `handle(Request $request): Response` runs the onion left-to-right; each
  middleware calls `$next($request)`.
- Members may be a `MiddlewareInterface` instance or a
  `callable(Request $request, callable $next): Response` — callables are
  normalized internally.

### `App` API
- `App::middleware(MiddlewareInterface|callable $m): void` — register global
  middleware (runs on every request).
- Route-registration methods (`get/post/put/patch/delete`) keep their current
  signatures and gain a trailing `array $options = []` parameter:
  - `'middleware' => [...]` — per-route middleware (runs before dispatch,
    after global).
  - `'auth' => ['required' => bool, 'scopes' => []]` — convenience flag
    handled by the built-in `AuthMiddleware` (see below).
- `App::dispatch`/`handle` unchanged externally: `handle(Request): Response`.
- 404 `{"detail":"Not Found"}`; 422 uses Falco's `loc`/`msg`/`type` error shape;
  `HttpException` status-code mapping unchanged.

## Shared Middleware — `Falco\Middleware`

### `RequestIdMiddleware`
- Generates RFC-4122 v4 UUID (`random_bytes` + byte mutation) when no
  `X-Request-Id` header; accepts an existing header only if it matches
  `[A-Za-z0-9-_]{1,64}` (prevents header injection in logs). Sets
  `X-Request-Id` on the response.

### `ErrorHandlerMiddleware`
- Maps: `ValidationException` → 422; `HttpException` → its `statusCode` with
  `getMessage()`; other `Throwable` → 500 `Internal Server Error`.
- In `debug` mode the 500 response echoes `getMessage()`; in prod it logs and
  returns the generic message. 422 keeps the `loc`/`msg`/`type` shape
  `{'detail': [{loc, msg, type}]}`.

### `RequestLoggingMiddleware`
- One structured INFO log line per request: `method`, `path`, `status`,
  `duration_ms`, `request_id`. No sensitive body/headers.
  Uses the `Logger` (see §4).

### `CorsMiddleware`
- Config: `allowed_origins` (default `[]` — disabled), `allowed_methods`
  (default `*`), `allowed_headers`, `expose_headers`, `max_age = 3600`,
  `allow_credentials = false`.
- Preflight (`OPTIONS` with `Access-Control-Request-Method`) → `204` with
  `Access-Control-Allow-*` headers.
- Non-preflight → echoes `Access-Control-Allow-Origin` when the Origin is
  allowed (wildcard `*` allowed).

### `SecurityHeadersMiddleware`
- Default headers on every response:
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`,
  `Strict-Transport-Security: max-age=31536000` when `hsts = true`.
  `Server:` header is not emitted by Falco.
- Can be configured/overridden per header; off by default via App unless
  explicitly registered (default config `additionalHeaders`).

### `RateLimitMiddleware`
- Constructor/ config: `maxRequests` (window `perSecond`, default 30/min),
  `const StoreInterface` keyed by IP; optional token-keying.
- In-memory store (array) by default; `RateLimitStoreInterface` provided so
  implementations can swap in Redis/DB later.
- Returns 429 with `Retry-After` and `X-RateLimit-Remaining / Limit`.

## Structured Logging — `Falco\Logging`

### `LoggerInterface`
```php
namespace Falco\Logging;
interface LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;
}
```
Std convenience: `debug/info/notice/error/critical`.

### Default logger + formatter
- `Falco\Logging\Logger` — `__construct(Falco\Logging\Formatter $formatter, mixed $stream)` default `php://output`.
- `Falco\Logging\JsonFormatter` — one JSON object per line:
  `{time, level, message, context, ...}` context keys merged at top level;
  `time` is `DATE_ATOM` with milliseconds. All context values stringified to
  avoid `json_encode` failures.
- Levels gate: a minimum level on the logger.

### Scoped: zero deps. No PSR-3 dependency (interface is compatible-by-shape
so a PSR-3 logger could be swapped in by the app).

## Security — `Falco\Security`

### `AuthMiddleware`
Wired per-route via route options (`['auth' => true]` or
`['auth' => ['required' => true, 'scopes' => [...]]]`).
- Reads `Authorization: Bearer <token>` (header key case-insensitive).
- Missing/invalid/expired token → 401 `{"detail":"Not authenticated"}`.
- Missing required scope → 403 `{"detail":"Not authorized"}`.
- On success, stores validated claims in the Request so the handler can read
  them via the DI container / param resolver.
- Runs only when a route opts in; never globally by default.

### `JwtService`
- `encode(array $claims, int $ttlSeconds): string` — HS256
  (`hash_hmac`), header `{alg: "HS256", typ: "JWT"}`, payload merges
  `iat`/`exp`; base64url encoding.
- `decode(string $token): array` — verifies signature
  (`hash_equals`), `exp`, `iat`; throws `Falco\Security\JwtException`
  (message: `invalid_token`, `expired`, `invalid_signature`).
- Secret from `Falco\Config\Config` (`app.jwt_secret`), validated non-empty
  and ≥ 32 chars.

### `RefreshTokenStore`
- Library-level interface `Falco\Security\RefreshTokenStoreInterface`:
  `issue(userId): string`, `consume(token): ?int` (returns userId or null),
  `revokeAll(userId)`.
- Provided `Falco\Data\RefreshTokenRepository` backed by a `refresh_tokens`
  table:
  - Token = 32 random bytes base64url; DB stores SHA-256 hash.
  - `consume` atomically marks the row consumed and returns the user id.
  - Tokens have `expires_at`. Rotation handled by the app flow: `/refresh`
    calls `consume`, revokes all consumed, issues a fresh pair.

## Health — `Falco\Health`

- `HealthController` adds `/health/live` (`{"status":"ok"}` always) and
  `/health/ready` (runs registered checks; default: DB connectivity via
  PDO `SELECT 1`). Ready returns 200+`{"status":"ok"}` or 503 with per-check
  details.
- Registered by the app when `health` enabled (default on).

## Metrics — `Falco\Metrics`

- `Registry` holds counters (`Counter`) and histograms (`Histogram`);
  names/labels Prometheus-friendly; Snake_case, no hyphens.
  Registered metrics:
  - Counter `falco_http_requests_total` (method, route, status)
  - Histogram `falco_http_request_duration_seconds` (method, route)
  - Current in-flight gauge via counter/counter total.
- `PrometheusTextFormatter` converts to text format
  (`# TYPE` lines, `_bucket/_sum/_count` for histograms).
- `MetricsMiddleware` records into the registry per request.
- `MetricsController` exposes `/metrics` in OpenMetrics text format when
  enabled.

## Config — `Falco\Config`

- `Config` simple immutable bag:
  `__construct(array $defaults)`, `get(string $key, mixed $default=null)`,
  with env override helper `fromEnv(array $map)` (e.g.
  `['jwt_secret' => 'FALCO_JWT_SECRET']`) string-casting.
- `.env.example` at repo root documenting every `.env` var. Framework
  itself only reads env via the app, not globals.

## Data — `Falco\Data`

- `Falco\Data\Connection` — thin PDO wrapper:
  - `__construct(string $dsn, string $user = '', string $pass = '',
    array $options = [])`; exposes `pdo()`.
  - `transaction(callable $cb): mixed` begin/commit + auto-rollback on
    Throwable.
- No ORM. No query builder. Repository pattern shown in the example:
  `items.php` and `users.php` are plain PDO repositories.

## Design — example app (`examples/items/app.php`)

- SQLite file DB (`FALCO_SQLITE_PATH`) with migrations `examples/items/
  migrations/001_init.sql`: tables `users` (id, username unique,
  password_hash, created_at), `refresh_tokens` (token_hash PK, user_id,
  expires_at, consumed_at NULL, created_at), `items` (id, user_id,
  name, price, created_at).
- `DB` factory: `Falco\Data\Connection::fromEnv`.
- Seed a demo user (`admin` / `FALCO_SEED_PASSWORD`) only when the env flag
  `FALCO_SEED=1` (dev).
- Endpoints:
  - `POST /login` `{username,password}` → 200 `{access_token, refresh_token}`
    (OAuth2-style bearer scheme), 401 `{"detail":"Incorrect username or
    password"}` on failure.
  - AuthMiddleware stores validated JWT claims on
    `$request->attributes` (`user` = claims array, `scopes`).
    Handlers receive them by type-hinting `Falco\Security\JwtClaims` (a
    tiny value object) — ParamResolver is extended to resolve it from
    request attributes (mirrors the existing `Request`/`Response`
    resolution). 401 `{"detail":"Not authenticated"}` if absent.
  - `POST /refresh` `{refresh_token}` → 200 new pair, rotation via
    `consume`; reuse of an already-consumed token → 401 and revokes all
    refresh tokens for that user (replay detection).
  - `POST /logout` — marks refresh token consumed.
  - Items CRUD, each scoped to `user_id` from JWT: `POST /items`,
    `GET /items`, `GET /items/{item_id}`, `DELETE /items/{item_id}`.
  - 404/422 error shapes unchanged.
- Middleware wired in app: RequestId, ErrorHandler, RequestLogging, Health,
  metrics (if enabled). Auth is per-route (auth on items; none on login).

## Security Notes

- JWT secret: min 32 chars, env-managed, never committed.
- Passwords hashed `password_hash` (bcrypt default).
- Refresh tokens stored hashed (SHA-256) not plaintext.
- Bearer-header token extraction; `Authorization` case-insensitive.
- Rate-limit: 429 shape `{detail: "Rate limit exceeded"}`.
- CORS defaulted OFF; explicitly enabled in app for a known-origin allowlist.

## Testing

- Unit: `MiddlewarePipeline`, `RequestIdMiddleware`, `ErrorHandlerMiddleware`,
  `RequestLoggingMiddleware`, `CorsMiddleware`, `SecurityHeadersMiddleware`,
  `RateLimitMiddleware`, `JwtService`, `RefreshTokenStore`,
  `HealthController`, `Registry/Exporter`, `Config`.
- Integration: `examples/items` app with `php -S` and direct
  `$app->handle(...)`:
  - login-success/failure; bearer-required 401; wrong-scope 403; CRUD
    create/read/delete; 404 non-owned; refresh rotation + old-token reuse →
    401; rate-limit → 429; health live/ready.
- Existing tests stay green.
- Verify via `php vendor/bin/phpunit`; smoke via `bin/falco
  serve examples/items/app.php`.

## Constraints

- Zero runtime dependencies. Verify with `composer show` + README note.
- PHP >= 8.1.
- No deployment kit / CI in scope.
- Middleware ordering: registration order = execution order (no reordering
  beyond that).

## Out of Scope (this iteration)

- OAuth2 flows beyond bearer JWT. Sessions. Multi-tenancy; verification
  emails, double opt-in, org/roles model.
- Middleware-per-route full ordering control beyond registration order.
- WebSockets, background jobs, distributed lock.
- PSR-7 compliance (stay value objects).
- Redis (documented extension point via store interfaces only).

## Deployment Roadmap (README)

- Production checklist: env vars, HTTPS/HSTS via reverse proxy, CORS
  allowlist, rate-limit tuning, JWT secret rotation, SQLite backups, run
  behind nginx/php-fpm or Swoole-behind-proxy. All external.
- Zero-dep statement.
# Falco

A FastAPI-style web framework for PHP 8.1+. Declarative route handlers, type-based validation, automatic OpenAPI docs.

## Install

```bash
git clone <this-repo> && cd FastAPI-PHP
php composer.phar install
```

## Quick start

```php
<?php // app.php
require 'vendor/autoload.php';

use Falco\App;

$app = new App(title: 'Hello', version: '0.1.0');

$app->get('/', function (): array {
    return ['message' => 'Hello, World!'];
});

return $app;
```

## Run

```bash
php bin/falco serve examples/items/app.php
# → http://127.0.0.1:8000
```

For a full, working example (CRUD with models, `#[Depends]` storage and `#[Query]` params), see `examples/items/app.php`.

Note: the example's `MemoryStore` keeps items in memory only for the lifetime of one request — the dev server reboots the app per request. Use it to demo the framework, not as storage.

## Features

- Type-hinted route handlers — query, path and body params resolved from plain PHP types
- `Model` classes with `fromArray` / `toArray` type coercion and validation
- `#[Depends]` dependency injection, `#[Query]`, `#[Header]`, `#[Body]` param attributes
- Path params resolved by name from the route template
- Automatic interactive API docs at `/docs` and OpenAPI schema at `/openapi.json`
- HTTP/1.1 built-in server (`php -S`) and an optional Swoole runtime (`--swoole`)
- Middleware pipeline with global and per-route middleware
- JWT authentication with refresh-token rotation
- Structured JSON logging
- Prometheus metrics (`/metrics`)
- Health checks (`/health/live`, `/health/ready`)
- CORS, security headers, and rate limiting
- Zero runtime dependencies — pure PHP 8.1+

## Production

For production-ready deployment, see `examples/items/` which includes:
- JWT auth with access + refresh token rotation
- `/health/live`, `/health/ready` endpoints
- `/metrics` for Prometheus scraping
- CORS, security headers, rate limiting, request ID, error handling
- Structured JSON request logging

### Environment

Create `.env` from `.env.example`:

```
FALCO_JWT_SECRET=<at least 32 chars>
FALCO_SQLITE_PATH=./data/app.sqlite
FALCO_CORS_ORIGINS=https://app.example.com
FALCO_METRICS=1
FALCO_SEED_PASSWORD=<password for admin user>
```

## Swoole note

`php bin/falco serve app.php --swoole` serves via Swoole when the `ext-swoole` extension is installed. Without it, Falco falls back to PHP's built-in server.
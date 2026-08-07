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

## Features

- Type-hinted route handlers — query, path and body params resolved from plain PHP types
- `Model` classes with `fromArray` / `toArray` type coercion and validation
- `#[Depends]` dependency injection, `#[Query]`, `#[Path]`, `#[Header]`, `#[Body]` param attributes
- Automatic interactive API docs at `/docs` and OpenAPI schema at `/openapi.json`
- HTTP/1.1 built-in server (`php -S`) and an optional Swoole runtime (`--swoole`)

## Swoole note

`php bin/falco serve app.php --swoole` serves via Swoole when the `ext-swoole` extension is installed. Without it, Falco falls back to PHP's built-in server.
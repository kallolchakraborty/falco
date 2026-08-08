# Production Guide

## Requirements

- PHP >= 8.1 (tested on 8.5)
- SQLite PDO driver (or any PDO-supported database)
- No required runtime dependencies (`composer install` only for dev tools)

## Environment Variables

| Variable | Required | Description |
|---|---|---|
| `FALCO_JWT_SECRET` | Yes | Secret key for JWT signing (min 32 chars) |
| `FALCO_SQLITE_PATH` | No | Path to SQLite database file (default: `data/app.sqlite`) |
| `FALCO_CORS_ORIGINS` | No | Comma-separated list of allowed CORS origins (default: `*` is insecure) |
| `FALCO_METRICS` | No | Set to `1` to enable Prometheus metrics at `/metrics` |
| `FALCO_SEED_PASSWORD` | No | If set, seeds an `admin` user with this password on startup |

Generate a secure JWT secret:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

## Deployment

### PHP Built-in Server (development/test)

```bash
php bin/falco serve examples/items/app.php --host=0.0.0.0 --port=8000
```

### Swoole (production)

```bash
php bin/falco serve examples/items/app.php --swoole
```

Requires `ext-swoole`. Behind a reverse proxy, set the proper headers (Swoole provides `$_SERVER['REMOTE_ADDR']` etc.).

### nginx + php-fpm

Point nginx to the app via a router script (`bin/server.php`). Run:
```bash
php-fpm -F  # or your system's php-fpm service
```

Configure nginx to serve static files and proxy PHP requests to the `bin/server.php` router.

## Security

- **JWT secrets**: use 32+ char random string; rotate via the `/refresh` endpoint.
- **HTTPS**: HSTS is NOT set by Falco itself — set it at your reverse proxy (nginx `Strict-Transport-Security` header).
- **CORS**: default is `*` (insecure). Set `FALCO_CORS_ORIGINS` explicitly.
- **Rate limiting**: default middleware is 100 req/min for demo. Adjust per deployment.
- **Error disclosure**: in production (`debug: false`), 500 errors return generic message. Keep `debug: false` in production.

## Backup

```bash
sqlite3 data/app.sqlite ".backup backup.sqlite"
```

## Monitoring

- `/health/live` — liveness probe (always 200).
- `/health/ready` — readiness probe (fails 503 if DB unreachable).
- `/metrics` — Prometheus format metrics (enable via `FALCO_METRICS=1`).

## Zero Dependencies

Falco has zero runtime dependencies — only requires PHP 8.1+ and PDO. No HTTP library, no DI container, no ORM. Composer is used only for development (PHPUnit).

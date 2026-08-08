# Production Guide

## Requirements

- PHP >= 8.1 (tested on 8.5)
- SQLite PDO driver (or any PDO-supported database)
- No required runtime dependencies (`composer install` only for dev tools)

## Environment

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

### Shared hosting (Apache / LiteSpeed / Hostinger Premium)

On shared hosting you cannot run a long-lived server (`php bin/falco serve`
or Swoole), but Falco runs fine per-request through Apache's PHP handler.
Use the front controller + `.htaccess` shipped in `examples/items/public/`:

```
examples/items/
├── app.php          # route definitions, returns App
├── conf.php         # env bootstrap -> returns app
├── env.example.php  # copy to env.php and edit values
├── data/            # writable dir for SQLite (chmod 664 or 775)
├── migrations/
└── public/          # <-- drop into your host's document root (public_html)
    ├── index.php    # front controller
    └── .htaccess
```

1. Keep the `items/` app outside your document root (it holds the JWT secret).
2. Copy `public/index.php` + `public/.htaccess` into `public_html/`.
3. Copy `env.example.php` → `env.php` and set a real `FALCO_JWT_SECRET`
   (≥32 random chars). Shared hosts usually don't expose real environment
   variables, so `conf.php` reads from `env.php` and falls back to `getenv()`.
4. Run `composer install --no-dev` once (via hPanel Composer or locally) to
   generate `vendor/autoload.php`; the `Falco\` PSR-4 mapping autoloads the
   framework. There are **no runtime packages** to install.
5. Ensure the SQLite path (default `data/app.sqlite`) points at a directory the
   PHP process can write to, and seed: set `FALCO_SEED_PASSWORD` in `env.php`.

`.htaccess` rewrites every route to `index.php`; static files are served
directly.

### PHP Built-in Server (development/test)

```bash
php bin/falco serve examples/items/app.php --host=0.0.0.0 --port=8000
```

### Swoole (production)

Requires `ext-swoole`. Behind a reverse proxy, set the proper headers (Swoole provides `$_SERVER['REMOTE_ADDR']` etc.).

```bash
php bin/falco serve examples/items/app.php --swoole
```

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

# Local env for shared hosting (e.g. Hostinger Premium).
# Copy to env.php and set values, then point public/index.php at conf.php.
# On hosts WITHOUT a real process environment these are the only way to supply
# FALCO_*; if a value is also set as a real env var, the env var wins.
return [
    'FALCO_JWT_SECRET' => 'change-me-0123456789abcdef0123456789abcdef',
    'FALCO_SQLITE_PATH' => __DIR__ . '/data/app.sqlite',
    'FALCO_CORS_ORIGINS' => '', // empty = deny cross-origin; comma list or '*' to allow
    'FALCO_METRICS' => '0',
    'FALCO_SEED_PASSWORD' => '',
    'FALCO_DEBUG' => '0', // '1' to expose 500 internals (dev only)
    'FALCO_RATE_LIMIT' => '100', // max requests per window per IP
    'FALCO_RATE_WINDOW' => '60', // window in seconds
    'FALCO_RATE_LIMIT_STORE' => 'memory', // 'memory' (in-process) or 'sqlite' (shared across php-fpm workers)
    'FALCO_SECURITY_HSTS' => '1', // set '0' behind a TLS-terminating proxy that already sends HSTS
];

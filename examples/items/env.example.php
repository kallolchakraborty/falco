# Local env for shared hosting (e.g. Hostinger Premium).
# Copy to env.php and set values, then point public/index.php at conf.php.
# On hosts WITHOUT a real process environment these are the only way to supply
# FALCO_*; if a value is also set as a real env var, the env var wins.
return [
    'FALCO_JWT_SECRET' => 'change-me-0123456789abcdef0123456789abcdef',
    'FALCO_SQLITE_PATH' => __DIR__ . '/data/app.sqlite',
    'FALCO_CORS_ORIGINS' => '',
    'FALCO_METRICS' => '0',
    'FALCO_SEED_PASSWORD' => '',
];

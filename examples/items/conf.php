<?php
// Shared-hosting env bootstrap.
// On hosts with no process environment (Hostinger Premium, etc.) this is how
// you feed FALCO_* into the app: copy examples/items/env.example.php to
// examples/items/env.php and edit the values. Environment/process vars found
// via getenv() are honored as a fallback, so this is a no-op locally if you
// already export real env vars.
$envFile = __DIR__ . '/env.php';
if (is_readable($envFile)) {
    $env = require $envFile;
    if (is_array($env)) {
        foreach ($env as $k => $v) {
            if (!is_string($v)) $v = (string) $v;
            // putenv + $_ENV so getenv()/Config both see it
            if (getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}

define('FALCO_APP_ROOT', dirname(__DIR__));

// Default SQLite location, inside a writable data/ dir next to the app.
if (!getenv('FALCO_SQLITE_PATH')) {
    putenv('FALCO_SQLITE_PATH=' . FALCO_APP_ROOT . '/data/app.sqlite');
    $_ENV['FALCO_SQLITE_PATH'] = FALCO_APP_ROOT . '/data/app.sqlite';
}

// Return the application bootstrap built by app.php.
return require __DIR__ . '/app.php';

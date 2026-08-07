<?php // bin/server.php
// PHP built-in server router. Run via: php -S localhost:8000 bin/server.php
// The app file path is passed through the FALCO_APP environment variable.
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require getenv('FALCO_APP');
if (!$app instanceof \Falco\App) {
    http_response_code(500);
    echo 'FALCO_APP must point to a file returning a Falco\App instance';
    return true;
}
$app->handle(\Falco\Request::fromGlobals())->send();
return true;
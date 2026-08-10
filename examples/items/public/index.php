<?php
// Front controller for shared hosting (Apache + LiteSpeed/CGI).
// Deploy: keep examples/items OUTSIDE document-root, drop this file + .htaccess
// into public_html/, and set FALCO_* in examples/items/env.php.
// This mirrors bin/server.php exactly — it just reads the app via conf.php instead of FALCO_APP.
$app = require __DIR__ . '/../conf.php';
$app->handle(\Falco\Request::fromGlobals())->send();

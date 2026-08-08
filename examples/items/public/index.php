<?php
// Front controller for shared hosting (Apache + LiteSpeed/CGI).
// Deploy: keep examples/items OUTSIDE document-root, drop this file +
// .htaccess into public_html/, and set env in examples/items/conf.php.
$app = require __DIR__ . '/../conf.php';
$app->handle(\Falco\Request::fromGlobals())->send();

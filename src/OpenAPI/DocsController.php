<?php // src/OpenAPI/DocsController.php
namespace Falco\OpenAPI;

use Falco\App;
use Falco\Response;

final class DocsController
{
    public function __construct(private App $app) {}

    public function openapi(): Response
    {
        return Response::json((new OpenApiGenerator())->generate($this->app));
    }

    public function docs(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><title>{TITLE} - Swagger UI</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>window.onload=()=>SwaggerUIBundle({url:'/openapi.json',dom_id:'#swagger-ui'})</script>
</body>
</html>
HTML;
        return new Response(200, ['content-type' => 'text/html'], str_replace('{TITLE}', htmlspecialchars($this->app->title), $html));
    }
}

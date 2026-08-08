<?php // src/Http/MiddlewareInterface.php
namespace Falco\Http;

use Falco\Request;
use Falco\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}

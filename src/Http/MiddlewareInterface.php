<?php // src/Http/MiddlewareInterface.php
namespace Falco\Http;

use Falco\Request;
use Falco\Response;

/**
 * PSR-15-style middleware contract: `(Request, callable $next): Response`.
 * Plain callables are also accepted by the pipeline, so you can mix class
 * middleware and closures in the same chain.
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}

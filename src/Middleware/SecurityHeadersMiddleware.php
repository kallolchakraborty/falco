<?php // src/Middleware/SecurityHeadersMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $hsts = true) {}

    public function handle(Request $request, callable $next): Response
    {
        $res = $next($request);
        $res->headers['x-content-type-options'] = 'nosniff';
        $res->headers['x-frame-options'] = 'DENY';
        $res->headers['referrer-policy'] = 'strict-origin-when-cross-origin';
        if ($this->hsts) $res->headers['strict-transport-security'] = 'max-age=31536000';
        return $res;
    }
}
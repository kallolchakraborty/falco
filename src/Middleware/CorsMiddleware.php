<?php // src/Middleware/CorsMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $origins,
        private array $methods = ['*'],
        private array $headers = ['content-type', 'authorization'],
        private int $maxAge = 3600,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->headers['origin'] ?? null;
        $allowOrigin = $origin !== null && (in_array('*', $this->origins, true) || in_array($origin, $this->origins, true))
            ? $origin : null;
        if ($request->method === 'OPTIONS' && isset($request->headers['access-control-request-method'])) {
            $headers = [
                'access-control-allow-methods' => implode(', ', $this->methods),
                'access-control-allow-headers' => implode(', ', $this->headers),
                'access-control-max-age' => (string) $this->maxAge,
            ];
            if ($allowOrigin !== null) $headers['access-control-allow-origin'] = $allowOrigin;
            return new Response(204, $headers, '');
        }
        $res = $next($request);
        if ($allowOrigin !== null) $res->headers['access-control-allow-origin'] = $allowOrigin;
        return $res;
    }
}

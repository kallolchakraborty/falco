<?php // src/Middleware/AuthMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;
use Falco\Security\JwtService;
use Falco\Security\JwtClaims;

/**
 * Per-route optional auth. Reads `Authorization: Bearer`, decodes via
 * {@see JwtService}, and attaches a {@see JwtClaims} to request attribute
 * 'user' for the resolver. `required: true` → 401 on missing/invalid;
 * `required: false` → passes through with no claims.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private JwtService $jwt, private bool $required = true) {}

    public function handle(Request $request, callable $next): Response
    {
        $auth = null;
        foreach ($request->headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $auth = $v; break; }
        }
        $token = $auth !== null && preg_match('/^Bearer\s+(.+)$/i', (string) $auth, $m) ? $m[1] : null;
        if ($token === null) {
            return $this->required ? Response::json(['detail' => 'Not authenticated'], 401) : $next($request);
        }
        try {
            $claims = new JwtClaims($this->jwt->decode($token));
        } catch (\Throwable $e) {
            return $this->required ? Response::json(['detail' => 'Not authenticated'], 401) : $next($request);
        }
        return $next($request->with('user', $claims));
    }
}

<?php // src/Middleware/ErrorHandlerMiddleware.php
namespace Falco\Middleware;

use Falco\Http\MiddlewareInterface;
use Falco\Request;
use Falco\Response;
use Falco\HttpException;
use Falco\Validation\ValidationException;

/**
 * Converts thrown exceptions into JSON responses:
 * ValidationException → 422, HttpException → its status code, other Throwable → 500
 * (internals only when `debug` is true).
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug = false) {}

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ValidationException $e) {
            return Response::json(['detail' => $e->errors], 422);
        } catch (HttpException $e) {
            return Response::json(['detail' => $e->getMessage()], $e->statusCode);
        } catch (\Throwable $e) {
            return Response::json(['detail' => $this->debug ? $e->getMessage() : 'Internal Server Error'], 500);
        }
    }
}

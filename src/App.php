<?php // src/App.php
namespace Falco;

use Falco\Params\ParamResolver;
use Falco\Validation\ValidationException;

final class App
{
    private Router $router;
    private ParamResolver $resolver;

    public function __construct(
        public string $title = 'Falco',
        public string $version = '0.1.0',
        public bool $docs = true,
        public bool $debug = false,
    ) {
        $this->router = new Router();
        $this->resolver = new ParamResolver();
    }

    public function get(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('GET', $path, $handler, $responseModel); }

    public function post(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('POST', $path, $handler, $responseModel); }

    public function put(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('PUT', $path, $handler, $responseModel); }

    public function patch(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('PATCH', $path, $handler, $responseModel); }

    public function delete(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('DELETE', $path, $handler, $responseModel); }

    public function handle(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return Response::json(['detail' => 'Not Found'], 404);
        }
        try {
            $args = $this->resolver->resolve($match->route->handler, $request, $match->pathParams);
            $result = ($match->route->handler)(...$args);
        } catch (ValidationException $e) {
            return Response::json(['detail' => $e->errors], 422);
        } catch (HttpException $e) {
            return Response::json(['detail' => $e->getMessage()], $e->statusCode);
        } catch (\Throwable $e) {
            return Response::json(['detail' => $this->debug ? $e->getMessage() : 'Internal Server Error'], 500);
        }
        if ($result instanceof Response) return $result;
        if ($result instanceof Model) $result = array_filter($result->toArray(), fn($v) => $v !== null);
        return Response::json($result);
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->router->routes();
    }
}
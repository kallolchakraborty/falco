<?php // src/App.php
namespace Falco;

use Falco\Http\MiddlewarePipeline;
use Falco\Http\MiddlewareInterface;
use Falco\Params\ParamResolver;
use Falco\Validation\ValidationException;

/**
 * Falco application: the only public entry point developers instantiate.
 *
 * It owns three things: a {@see Router} (route table), a {@see ParamResolver}
 * (argument binding) and a list of **global** middleware. A request flows as:
 *
 *  handle() → global MiddlewarePipeline → dispatch() → [per-route MiddlewarePipeline?] → invokeHandler()
 *
 * `dispatch()` matches the route; routes that declare `options['middleware']`
 * get their own nested pipeline, otherwise the handler is invoked directly.
 * OpenAPI docs (`/openapi.json`, `/docs`) are registered automatically unless `docs: false`.
 */
final class App
{
    private Router $router;
    private ParamResolver $resolver;

    /** @var (MiddlewareInterface|callable)[] */
    private array $middleware = [];

    public function __construct(
        public string $title = 'Falco',
        public string $version = '0.1.0',
        public bool $docs = true,
        public bool $debug = false,
    ) {
        $this->router = new Router();
        $this->resolver = new ParamResolver();
        if ($this->docs) {
            $docs = new OpenAPI\DocsController($this);
            $this->get('/openapi.json', fn() => $docs->openapi());
            $this->get('/docs', fn() => $docs->docs());
        }
    }

    public function middleware(MiddlewareInterface|callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function get(string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    { $this->router->add('GET', $path, $handler, $responseModel, $options); }

    public function post(string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    { $this->router->add('POST', $path, $handler, $responseModel, $options); }

    public function put(string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    { $this->router->add('PUT', $path, $handler, $responseModel, $options); }

    public function patch(string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    { $this->router->add('PATCH', $path, $handler, $responseModel, $options); }

    public function delete(string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    { $this->router->add('DELETE', $path, $handler, $responseModel, $options); }

    /**
     * Dispatch a request through global middleware, then into routing.
     * The global pipeline's terminal is a closure over {@see dispatch()}.
     */
    public function handle(Request $request): Response
    {
        $terminal = fn(Request $r): Response => $this->dispatch($r);
        $pipeline = new MiddlewarePipeline($this->middleware, $terminal);
        return $pipeline->handle($request);
    }

    /**
     * Match the route, run any per-route middleware, then invoke the handler.
     * Per-route middleware is wrapped in a *nested* pipeline whose terminal is
     * {@see invokeHandler()}, so auth/etc. run only for that route.
     */
    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return Response::json(['detail' => 'Not Found'], 404);
        }
        $perRoute = $match->route->options['middleware'] ?? [];
        $terminal = fn(Request $r): Response => $this->invokeHandler($match, $r);
        if ($perRoute) {
            $inner = new MiddlewarePipeline($perRoute, $terminal);
            return $inner->handle($request);
        }
        return $terminal($request);
    }

    /**
     * Resolve arguments via {@see ParamResolver}, call the handler, and normalize
     * the return into a Response. Exceptions are mapped to the documented HTTP
     * status codes (422 / HttpException code / 500 debug-gated).
     */
    private function invokeHandler(\Falco\RouteMatch $match, Request $request): Response
    {
        try {
            $args = $this->resolver->resolve($match->route->handler, $request, $match->pathParams);
            $result = ($match->route->handler)(...$args);
        } catch (ValidationException $e) {
            // FastAPI-shaped validation errors -> 422
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

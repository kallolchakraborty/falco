<?php // src/Router.php
namespace Falco;

/**
 * Static route table with compile-once path matching.
 *
 * Routes are stored in insertion order and matched by linear scan (O(routes)).
 * Each route's path template is compiled to a single anchored PCRE exactly once
 * and memoized in {@see $compiled}, so matching is a cheap `preg_match` per
 * request after warmup.
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var array<string,string> Memoized compiled regexes keyed by path template. */
    private array $compiled = [];

    public function add(string $method, string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    {
        $this->routes[] = new Route($method, $path, $handler, $responseModel, $options);
    }

    public function match(string $method, string $path): ?RouteMatch
    {
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        foreach ($this->routes as $route) {
            if ($route->method !== $method) continue;
            $params = $this->matchTemplate($route->path, $path);
            if ($params !== null) {
                return new RouteMatch($route, $params);
            }
        }
        return null;
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Match a request path against a route template, returning named path params.
     *
     * Templates use `{name}` segments; each becomes a named capture `(?P<name>[^/]+)`
     * so a single segment can never consume a `/` (no greedy over-matching, no regex
     * injection — names are `\w+` only). The compiled PCRE is cached per template.
     */
    private function matchTemplate(string $template, string $path): ?array
    {
        $regex = $this->compiled[$template] ??= $this->compile($template);
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) $params[$key] = $value;
        }
        return $params;
    }

    private function compile(string $template): string
    {
        $parts = preg_split('/\{(\w+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '^';
        for ($i = 0; $i < count($parts); $i += 2) {
            $regex .= preg_quote($parts[$i], '#');
            if (isset($parts[$i + 1])) {
                $regex .= '(?P<' . $parts[$i + 1] . '>[^/]+)';
            }
        }
        return '#' . $regex . '$#';
    }
}

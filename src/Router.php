<?php // src/Router.php
namespace Falco;

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, ?string $responseModel = null, array $options = []): void
    {
        $this->routes[] = new Route($method, $path, $handler, $responseModel, $options);
    }

    public function match(string $method, string $path): ?RouteMatch
    {
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

    private function matchTemplate(string $template, string $path): ?array
    {
        $parts = preg_split('/\{(\w+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '^';
        for ($i = 0; $i < count($parts); $i += 2) {
            $regex .= preg_quote($parts[$i], '#');
            if (isset($parts[$i + 1])) {
                $regex .= '(?P<' . $parts[$i + 1] . '>[^/]+)';
            }
        }
        $regex .= '$';
        if (!preg_match('#' . $regex . '#', $path, $matches)) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) $params[$key] = $value;
        }
        return $params;
    }
}

<?php // src/Http/MiddlewarePipeline.php
namespace Falco\Http;

use Falco\Request;
use Falco\Response;

final class MiddlewarePipeline
{
    /** @var (MiddlewareInterface|callable)[] */
    private array $middleware;

    public function __construct(array $middleware, private $terminal)
    {
        $this->middleware = array_values($middleware);
    }

    public function handle(Request $request): Response
    {
        return $this->invoke(0, $request);
    }

    private function invoke(int $index, Request $request): Response
    {
        if ($index >= count($this->middleware)) {
            return ($this->terminal)($request);
        }
        $mw = $this->middleware[$index];
        $next = fn(Request $r): Response => $this->invoke($index + 1, $r);
        if ($mw instanceof MiddlewareInterface) {
            return $mw->handle($request, $next);
        }
        return $mw($request, $next);
    }
}
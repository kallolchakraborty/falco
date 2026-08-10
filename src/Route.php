<?php // src/Route.php
namespace Falco;

/**
 * A single registered route: HTTP method, path template, handler, and
 * optional response model + per-route options (e.g. `middleware`).
 */
final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly ?string $responseModel = null,
        public readonly array $options = [],
    ) {}
}

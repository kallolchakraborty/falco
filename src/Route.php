<?php // src/Route.php
namespace Falco;

final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly ?string $responseModel = null,
    ) {}
}

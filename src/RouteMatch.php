<?php // src/RouteMatch.php
namespace Falco;

final class RouteMatch
{
    public function __construct(
        public readonly Route $route,
        public readonly array $pathParams,
    ) {}
}

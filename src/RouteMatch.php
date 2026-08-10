<?php // src/RouteMatch.php
namespace Falco;

/**
 * Result of a successful {@see Router::match()}: the matched {@see Route}
 * plus the named path parameters extracted from the URL.
 */
final class RouteMatch
{
    public function __construct(
        public readonly Route $route,
        public readonly array $pathParams,
    ) {}
}

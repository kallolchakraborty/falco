<?php // src/Params/Body.php
namespace Falco\Params;

/**
 * Attribute: resolve this parameter from the JSON request body
 * (instead of the default query-string resolution).
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Body
{
    public function __construct(public readonly ?string $alias = null) {}
}

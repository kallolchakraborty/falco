<?php // src/Params/Query.php
namespace Falco\Params;

/** Attribute: resolve this parameter from the query string (the default for unnamed scalars). */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Query
{
    public function __construct(public readonly ?string $alias = null) {}
}

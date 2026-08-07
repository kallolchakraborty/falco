<?php // src/Params/Query.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Query
{
    public function __construct(public readonly ?string $alias = null) {}
}
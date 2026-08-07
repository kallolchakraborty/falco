<?php // src/Params/Body.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Body
{
    public function __construct(public readonly ?string $alias = null) {}
}
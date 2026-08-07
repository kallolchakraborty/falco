<?php // src/Params/Path.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Path
{
    public function __construct(public readonly ?string $alias = null) {}
}
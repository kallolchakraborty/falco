<?php // src/Params/Depends.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Depends
{
    public function __construct(public readonly string|array|null $callable = null) {}
}

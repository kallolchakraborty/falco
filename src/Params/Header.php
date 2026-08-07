<?php // src/Params/Header.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Header
{
    public function __construct(public readonly ?string $alias = null) {}
}
<?php // src/Params/Depends.php
namespace Falco\Params;

/**
 * Attribute: dependency-injection. `callable` may be a function name,
 * a `[Class, 'static']` pair, or a `Class::__invoke` (resolved via reflection).
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Depends
{
    public function __construct(public readonly string|array|null $callable = null) {}
}

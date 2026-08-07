<?php // src/Params/DependencyContainer.php
namespace Falco\Params;

use Falco\Request;

final class DependencyContainer
{
    private array $cache = [];

    public function resolve(\ReflectionParameter $param, Request $request): mixed
    {
        $type = $param->getType();
        $callable = null;
        $attrs = $param->getAttributes(Depends::class);
        if (!empty($attrs)) {
            $callable = $attrs[0]->newInstance()->callable;
        }
        if ($callable === null && $type instanceof \ReflectionNamedType) {
            $callable = [$type->getName(), '__invoke'];
        }
        $key = is_array($callable) ? implode('::', $callable) : (string) $callable;
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->build($callable);
        }
        return $this->cache[$key];
    }

    private function build(mixed $callable): mixed
    {
        if (is_string($callable) && function_exists($callable)) {
            return $callable();
        }
        if (is_array($callable) && class_exists($callable[0])) {
            $class = $callable[0];
            $ref = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            $args = [];
            if ($ctor) {
                foreach ($ctor->getParameters() as $p) {
                    if ($p->isDefaultValueAvailable()) {
                        $args[] = $p->getDefaultValue();
                    } else {
                        throw new \LogicException("Cannot autowire non-optional ctor param \${$p->getName()} of $class");
                    }
                }
            }
            return $ref->newInstanceArgs($args);
        }
        throw new \LogicException('Unsupported dependency: ' . json_encode($callable));
    }
}
<?php

namespace Falco\Security;

final class JwtClaims implements \ArrayAccess
{
    public function __construct(private array $claims) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->claims;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->claims[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->claims[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->claims[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->claims[$offset]);
    }
}

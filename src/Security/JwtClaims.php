<?php

namespace Falco\Security;

final class JwtClaims
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
}
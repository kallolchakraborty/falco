<?php
namespace Falco\Config;

final class Config
{
    public function __construct(private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

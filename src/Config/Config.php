<?php
namespace Falco\Config;

/**
 * Minimal config bag backed by a plain array. The example app builds it once
 * from env() calls, then reads typed values via `get()`.
 */
final class Config
{
    public function __construct(private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

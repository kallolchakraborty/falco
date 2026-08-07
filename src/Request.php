<?php // src/Request.php
namespace Falco;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $body,
        public readonly array $attributes = [],
        public readonly string $ip = '',
    ) {}

    public function with(string $key, mixed $value): self
    {
        return new self(
            $this->method, $this->path, $this->query, $this->headers, $this->body,
            [...$this->attributes, $key => $value],
            $this->ip,
        );
    }

    public static function fromGlobals(): self
    {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $body = [];
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $headers, $body, [], $_SERVER['REMOTE_ADDR'] ?? '');
    }
}

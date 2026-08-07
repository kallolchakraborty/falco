<?php // src/Response.php
namespace Falco;

final class Response
{
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public mixed $body = null,
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, ['content-type' => 'application/json'], $data);
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($status, ['content-type' => 'text/plain; charset=utf-8'], $content);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo is_string($this->body) ? $this->body : json_encode($this->body);
    }
}

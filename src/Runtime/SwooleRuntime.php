<?php // src/Runtime/SwooleRuntime.php
namespace Falco\Runtime;

use Falco\App;
use Falco\Request;

final class SwooleRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8000,
    ) {}

    public function serve(App $app): never
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException('Swoole extension not loaded. Install via: pecl install swoole');
        }
        $server = new \Swoole\Http\Server($this->host, $this->port);
        $server->on('request', function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($app) {
            $path = parse_url($req->server['request_uri'] ?? '/', PHP_URL_PATH) ?: '/';
            $body = json_decode($req->getContent() ?: '', true);
            if (json_last_error() !== JSON_ERROR_NONE) $body = [];
            $request = new Request(
                $req->server['request_method'] ?? 'GET',
                $path,
                $req->get ?? [],
                array_change_key_case($req->header ?? [], CASE_LOWER),
                $body,
            );
            $response = $app->handle($request);
            $res->status($response->status);
            foreach ($response->headers as $name => $value) {
                $res->header($name, $value);
            }
            $res->end(is_string($response->body) ? $response->body : json_encode($response->body));
        });
        $server->start();
    }
}
<?php // src/OpenAPI/OpenApiGenerator.php
namespace Falco\OpenAPI;

use Falco\App;
use Falco\Model;
use Falco\Params\Depends;
use Falco\Request;
use Falco\Response;
use Falco\Route;

final class OpenApiGenerator
{
    public function generate(App $app): array
    {
        $schemas = [];
        $paths = [];
        foreach ($app->routes() as $route) {
            $paths[$route->path][strtolower($route->method)] = $this->operation($route, $schemas);
        }
        $doc = [
            'openapi' => '3.1.0',
            'info' => ['title' => $app->title, 'version' => $app->version],
            'paths' => $paths,
        ];
        if ($schemas) {
            $doc['components'] = ['schemas' => $schemas];
        }
        return $doc;
    }

    private function operation(Route $route, array &$schemas): array
    {
        $builder = new SchemaBuilder();
        $ref = new \ReflectionFunction(\Closure::fromCallable($route->handler));
        $parameters = [];
        $requestBody = null;
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();
            if (preg_match('/\{' . preg_quote($name, '/') . '\}/', $route->path)) {
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $builder->fromType($type)];
                continue;
            }
            if (!empty($param->getAttributes(Depends::class))) {
                continue;
            }
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName && is_subclass_of($typeName, Model::class)) {
                $key = (new \ReflectionClass($typeName))->getShortName();
                $schemas[$key] ??= $builder->fromModel($typeName);
                $requestBody = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $key]]],
                ];
                continue;
            }
            if ($typeName === Request::class || $typeName === Response::class) continue;
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => !$param->isDefaultValueAvailable(),
                'schema' => $builder->fromType($type),
            ];
        }
        $op = [
            'operationId' => strtolower($route->method) . '_' . trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $route->path), '_'),
            'parameters' => $parameters,
            'responses' => $this->responses($route, $builder, $schemas),
        ];
        if ($requestBody) $op['requestBody'] = $requestBody;
        return $op;
    }

    private function responses(Route $route, SchemaBuilder $builder, array &$schemas): array
    {
        $content = null;
        if ($route->responseModel) {
            $key = (new \ReflectionClass($route->responseModel))->getShortName();
            $schemas[$key] ??= $builder->fromModel($route->responseModel);
            $content = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $key]]];
        }
        $response = ['description' => 'Successful Response'];
        if ($content !== null) {
            $response['content'] = $content;
        }
        $responses = ['200' => $response];
        $responses['422'] = ['description' => 'Validation Error'];
        return $responses;
    }
}

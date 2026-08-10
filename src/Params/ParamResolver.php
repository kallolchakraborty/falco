<?php // src/Params/ParamResolver.php
namespace Falco\Params;

use Falco\HttpException;
use Falco\Model;
use Falco\Request;
use Falco\Response;
use Falco\Security\JwtClaims;
use Falco\Validation\Validator;
use Falco\Validation\ValidationException;

/**
 * Binds a route handler's parameters by reflection, in this priority order:
 *
 *  1. #[Depends]            → DependencyContainer (DI)
 *  2. Request / Response    → framework singletons
 *  3. Model subclass        → treated as #[Body] and validated
 *  4. JwtClaims             → read from request attribute 'user' (set by AuthMiddleware)
 *  5. path parameter        → from RouteMatch.pathParams, coerced to type
 *  6. #[Header]             → request header (lowercased, case-insensitive)
 *  7. #[Body]               → JSON body, coerced to type
 *  8. #[Query] / (default)  → query-string value, coerced to type
 *
 * A bare scalar/array parameter with no attribute defaults to query resolution
 * (matching FastAPI ergonomics). Missing required values throw a
 * FastAPI-shaped ValidationException (422).
 */
final class ParamResolver
{
    public function __construct(
        private readonly Validator $validator = new Validator(),
        private readonly DependencyContainer $container = new DependencyContainer(),
    ) {}

    public function resolve(callable $handler, Request $request, array $pathParams): array
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        $args = [];
        foreach ($ref->getParameters() as $param) {
            $args[$param->getName()] = $this->resolveParam($param, $request, $pathParams);
        }
        return $args;
    }

    private function resolveParam(\ReflectionParameter $param, Request $request, array $pathParams): mixed
    {
        $type = $param->getType();
        $name = $param->getName();

        if (!empty($param->getAttributes(Depends::class))) {
            return $this->container->resolve($param, $request);
        }
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
            if ($typeName === Request::class) return $request;
            if ($typeName === Response::class) return new Response();
            if (is_subclass_of($typeName, Model::class)) {
                return $this->validator->coerce($request->body, $type, ['body', $name]);
            }
            if ($typeName === JwtClaims::class) {
                $claims = $request->attributes['user'] ?? null;
                if ($claims instanceof JwtClaims) return $claims;
                throw new HttpException(401, 'Not authenticated');
            }
        }
        if (isset($pathParams[$name])) {
            return $this->validator->coerce($pathParams[$name], $type, ['path', $name]);
        }
        if (!empty($param->getAttributes(Header::class))) {
            $headerAttr = $param->getAttributes(Header::class)[0]->newInstance();
            $key = strtolower(str_replace('_', '-', $headerAttr->alias ?? $name));
            $value = $request->headers[$key] ?? null;
            return $this->valueOrThrow($param, $value, ['header', $name]);
        }
        if (!empty($param->getAttributes(Body::class))) {
            return $this->validator->coerce($request->body, $type, ['body', $name]);
        }
        $queryAttrs = $param->getAttributes(Query::class);
        $queryKey = $queryAttrs ? ($queryAttrs[0]->newInstance()->alias ?? $name) : $name;
        $value = $request->query[$queryKey] ?? null;
        return $this->valueOrThrow($param, $value, ['query', $name]);
    }

    private function valueOrThrow(\ReflectionParameter $param, mixed $value, array $loc): mixed
    {
        if ($value === null) {
            if ($param->isDefaultValueAvailable()) return $param->getDefaultValue();
            $type = $param->getType();
            if ($type && $type->allowsNull()) return null;
            throw new ValidationException([['loc' => $loc, 'msg' => 'Field required', 'type' => 'missing']]);
        }
        return $this->validator->coerce($value, $param->getType(), $loc);
    }
}

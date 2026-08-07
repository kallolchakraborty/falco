# Falco — FastAPI-style PHP Framework, Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build **Falco**, a PHP framework that faithfully mirrors FastAPI's API surface — attribute-based parameter resolution, type-driven validation (Pydantic-style), automatic OpenAPI + Swagger docs, dependency injection, and a runtime-agnostic core with a Swoole async adapter.

**Architecture:** Three layers mirroring FastAPI's stack. Core HTTP objects (`Request`/`Response`/`Router` — the Starlette analog); validation (`Model`/`Validator` — the Pydantic analog); and the `App` facade (the FastAPI layer) that wires reflection-based param resolution, serialization, OpenAPI generation, and runtime servers together. The App is runtime-agnostic (like an ASGI app); `bin/falco` CLI + Swoole adapter play the uvicorn role.

**Tech Stack:** PHP ≥ 8.1 (8.5.9 installed), Composer (PSR-4 autoload), PHPUnit 12 (dev), PHP built-in server (dev runtime), Swoole extension (production async runtime, optional).

## Global Constraints

- PHP ≥ 8.1 required; all code uses readonly properties, named args, enums, attributes.
- No runtime dependencies beyond the PHP standard library. Composer only for autoload + PHPUnit (dev).
- Framework namespace: `Falco\` → `src/`. Tests: `Falco\Tests\` → `tests/`.
- Everything JSON-in/JSON-out with FastAPI-style error shapes: 404 `{"detail":"Not Found"}`, 422 `{"detail":[{"loc":["query","q"],"msg":"...","type":"..."}]}`.
- Param resolution mirrors FastAPI: name-matches-path-template → path param; `Model` subclass type → body; `Request`/`Response` types → injected; else → query. Attributes (`#[Path]`, `#[Query]`, `#[Body]`, `#[Header]`, `#[Depends]`) are explicit overrides.
- OpenAPI version: 3.1.0. Docs enabled by default at `/docs` + `/openapi.json`.
- Swoole adapter is guarded by `extension_loaded('swoole')`; its real-server behavior is NOT verifiable locally (extension not installed) — this is a known, stated limitation, not hidden.

---

### Task 0: Bootstrap project (Composer, PHPUnit, git)

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`

**Interfaces:**
- Produces: PSR-4 autoload mapping `Falco\` → `src/`, PHPUnit configured, empty git repo.

- [ ] **Step 1: Install Composer (local `composer.phar`)**
  Run: `php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && php composer-setup.php && rm composer-setup.php`
  Expected: `composer.phar` in project root.

- [ ] **Step 2: Init git and write composer.json**

```json
{
    "name": "falco/falco",
    "description": "FastAPI-style web framework for PHP",
    "type": "library",
    "license": "MIT",
    "require": { "php": ">=8.1" },
    "require-dev": { "phpunit/phpunit": "^12" },
    "autoload": { "psr-4": { "Falco\\": "src/" } },
    "autoload-dev": { "psr-4": { "Falco\\Tests\\": "tests/" } },
    "bin": ["bin/falco"]
}
```

```xml
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Falco">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

```gitignore
/vendor/
/composer.phar
/composer-setup.php
/.phpunit.cache/
```

- [ ] **Step 3: Install PHPUnit + generate autoloader**
  Run: `php composer.phar install`
  Expected: `vendor/` created, `vendor/autoload.php` exists.

- [ ] **Step 4: Commit**
```bash
git init -b main
git add composer.json phpunit.xml .gitignore docs/superpowers/plans/2026-08-08-falco-implementation.md
git commit -m "chore: bootstrap falco project with composer and phpunit"
```

---

### Task 1: Core HTTP objects — Request, Response, HttpException

**Files:**
- Create: `src/Request.php`, `src/Response.php`, `src/HttpException.php`
- Test: `tests/RequestTest.php`, `tests/ResponseTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Request::__construct(string $method, string $path, array $query, array $headers, array $body)` (all readonly)
  - `Request::fromGlobals(): self`
  - `Response::json(mixed $data, int $status = 200): self`
  - `Response::send(): void`
  - `HttpException::__construct(int $statusCode, string $detail)`

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/ResponseTest.php
namespace Falco\Tests;

use Falco\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJson(): void
    {
        $r = Response::json(['ok' => true], 201);
        $this->assertSame(201, $r->status);
        $this->assertSame(['ok' => true], $r->body);
    }
}
```

```php
<?php // tests/RequestTest.php
namespace Falco\Tests;

use Falco\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testConstruction(): void
    {
        $req = new Request('GET', '/items/1', ['q' => 'x'], [], []);
        $this->assertSame('/items/1', $req->path);
        $this->assertSame('x', $req->query['q']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**
  Run: `php vendor/bin/phpunit --filter ResponseTest`
  Expected: FAIL — class not found.

- [ ] **Step 3: Implement the three classes**

```php
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
    ) {}

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
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $headers, $body);
    }
}
```

```php
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

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo is_string($this->body) ? $this->body : json_encode($this->body);
    }
}
```

```php
<?php // src/HttpException.php
namespace Falco;

class HttpException extends \Exception
{
    public function __construct(public readonly int $statusCode, string $detail)
    {
        parent::__construct($detail);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**
  Run: `php vendor/bin/phpunit`
  Expected: PASS (2 tests).

- [ ] **Step 5: Commit**
```bash
git add src/Request.php src/Response.php src/HttpException.php tests/RequestTest.php tests/ResponseTest.php
git commit -m "feat: core request/response objects"
```

---

### Task 2: Validation core — Validator + Model + ValidationException

**Files:**
- Create: `src/Validation/Validator.php`, `src/Validation/ValidationException.php`, `src/Model.php`
- Test: `tests/ValidatorTest.php`, `tests/ModelTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ValidationException::__construct(array $errors)` where each error = `['loc' => string[], 'msg' => string, 'type' => string]`
  - `Validator::coerce(mixed $value, ?\ReflectionType $type, array $loc): mixed`
  - `Model::fromArray(array $data): static`
  - `Model::toArray(): array`

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/ValidatorTest.php
namespace Falco\Tests;

use Falco\Validation\Validator;
use Falco\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

enum Status: string { case Active = 'active'; case Closed = 'closed'; }

final class ValidatorTest extends TestCase
{
    public function testCoerceIntFromString(): void
    {
        $v = new Validator();
        $this->assertSame(5, $v->coerce('5', new \ReflectionNamedType('int'), ['query', 'x']));
    }

    public function testRejectsBadInt(): void
    {
        $this->expectException(ValidationException::class);
        (new Validator())->coerce('abc', new \ReflectionNamedType('int'), ['query', 'x']);
    }

    public function testNullable(): void
    {
        $v = new Validator();
        $this->assertNull($v->coerce(null, new \ReflectionNamedType('string', true), ['query', 'x']));
    }

    public function testEnum(): void
    {
        $v = new Validator();
        $this->assertSame(Status::Active, $v->coerce('active', new \ReflectionNamedType(Status::class), ['query', 's']));
    }
}
```

```php
<?php // tests/ModelTest.php
namespace Falco\Tests;

use Falco\Model;
use Falco\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class Item extends Model
{
    public string $name;
    public float $price;
    public ?string $note = null;
}

final class ModelTest extends TestCase
{
    public function testFromArray(): void
    {
        $item = Item::fromArray(['name' => 'Widget', 'price' => 9.99]);
        $this->assertSame('Widget', $item->name);
        $this->assertSame(9.99, $item->price);
        $this->assertNull($item->note);
    }

    public function testMissingRequiredField(): void
    {
        $this->expectException(ValidationException::class);
        Item::fromArray(['price' => 1.0]);
    }

    public function testToArray(): void
    {
        $item = Item::fromArray(['name' => 'W', 'price' => 1, 'note' => 'n']);
        $this->assertSame(['name' => 'W', 'price' => 1.0, 'note' => 'n'], $item->toArray());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**
  Run: `php vendor/bin/phpunit`
  Expected: FAIL — classes not found.

- [ ] **Step 3: Implement Validator, ValidationException, Model**

```php
<?php // src/Validation/ValidationException.php
namespace Falco\Validation;

final class ValidationException extends \Exception
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed');
    }
}
```

```php
<?php // src/Validation/Validator.php
namespace Falco\Validation;

use Falco\Model;

final class Validator
{
    public function coerce(mixed $value, ?\ReflectionType $type, array $loc): mixed
    {
        if ($type === null) {
            return $value;
        }
        if ($type instanceof \ReflectionUnionType) {
            $types = $type->getTypes();
            $nullable = false;
            foreach ($types as $t) {
                if ($t->getName() === 'null') { $nullable = true; break; }
            }
            if ($value === null) {
                if ($nullable) return null;
                throw new ValidationException([$this->err($loc, 'Input should not be null', 'nullable_type')]);
            }
            $last = null;
            foreach ($types as $t) {
                if ($t->getName() === 'null') continue;
                try {
                    return $this->coerceNamed($value, $t, $loc);
                } catch (ValidationException $e) {
                    $last = $e;
                }
            }
            throw $last ?? new ValidationException([$this->err($loc, 'Input does not match any type', 'union_type')]);
        }
        if ($type instanceof \ReflectionNamedType) {
            return $this->coerceNamed($value, $type, $loc);
        }
        return $value;
    }

    private function coerceNamed(mixed $value, \ReflectionNamedType $type, array $loc): mixed
    {
        if ($type->allowsNull() && $value === null) {
            return null;
        }
        return match ($type->getName()) {
            'int' => $this->int($value, $loc),
            'float' => $this->float($value, $loc),
            'string' => $this->string($value, $loc),
            'bool' => $this->bool($value, $loc),
            'array' => is_array($value) ? $value : throw $this->err($loc, 'Input should be an array', 'array_type'),
            'null' => null,
            default => $this->modelOrEnum($value, $type->getName(), $loc),
        };
    }

    private function int(mixed $v, array $loc): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int) $v;
        if (is_float($v) && floor($v) === $v) return (int) $v;
        throw $this->err($loc, 'Input should be a valid integer', 'int_parsing');
    }

    private function float(mixed $v, array $loc): float
    {
        if (is_int($v)) return (float) $v;
        if (is_float($v)) return $v;
        if (is_string($v) && is_numeric($v)) return (float) $v;
        throw $this->err($loc, 'Input should be a valid number', 'float_parsing');
    }

    private function string(mixed $v, array $loc): string
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string) $v;
        throw $this->err($loc, 'Input should be a valid string', 'string_type');
    }

    private function bool(mixed $v, array $loc): bool
    {
        if (is_bool($v)) return $v;
        if (in_array($v, [1, '1', 'true', 'True', 'TRUE'], true)) return true;
        if (in_array($v, [0, '0', 'false', 'False', 'FALSE'], true)) return false;
        throw $this->err($loc, 'Input should be a valid boolean', 'bool_parsing');
    }

    private function modelOrEnum(mixed $v, string $typeName, array $loc): mixed
    {
        if (is_subclass_of($typeName, Model::class)) {
            if (!is_array($v)) throw $this->err($loc, 'Input should be an object', 'model_type');
            return $typeName::fromArray($v);
        }
        if (is_subclass_of($typeName, \BackedEnum::class)) {
            foreach ($typeName::cases() as $case) {
                if ($case->value === $v) return $case;
            }
            throw $this->err($loc, 'Input should be a valid enum value', 'enum_parsing');
        }
        throw $this->err($loc, 'Unsupported parameter type', 'unsupported_type');
    }

    private function err(array $loc, string $msg, string $type): ValidationException
    {
        return new ValidationException([['loc' => $loc, 'msg' => $msg, 'type' => $type]]);
    }
}
```

```php
<?php // src/Model.php
namespace Falco;

use Falco\Validation\Validator;
use Falco\Validation\ValidationException;

abstract class Model
{
    public static function fromArray(array $data): static
    {
        $validator = new Validator();
        $instance = new static();
        $ref = new \ReflectionClass(static::class);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $name = $prop->getName();
            if (!array_key_exists($name, $data)) {
                if ($prop->hasDefaultValue()) continue;
                if ($prop->getType()?->allowsNull()) continue;
                throw new ValidationException([[
                    'loc' => ['body', $name],
                    'msg' => 'Field required',
                    'type' => 'missing',
                ]]);
            }
            $instance->$name = $validator->coerce($data[$name], $prop->getType(), ['body', $name]);
        }
        return $instance;
    }

    public function toArray(): array
    {
        $result = [];
        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $value = $this->{$prop->getName()};
            $result[$prop->getName()] = $value instanceof self ? $value->toArray() : $value;
        }
        return $result;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**
  Run: `php vendor/bin/phpunit`
  Expected: PASS (8 tests).

- [ ] **Step 5: Commit**
```bash
git add src/Validation/ src/Model.php tests/ValidatorTest.php tests/ModelTest.php
git commit -m "feat: type-driven validator and model base class"
```

---

### Task 3: Router — template matching

**Files:**
- Create: `src/Route.php`, `src/RouteMatch.php`, `src/Router.php`
- Test: `tests/RouterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Route::__construct(string $method, string $path, callable $handler, ?string $responseModel = null)` (readonly props)
  - `RouteMatch::__construct(Route $route, array $pathParams)`
  - `Router::add(string $method, string $path, callable $handler, ?string $responseModel = null): void`
  - `Router::match(string $method, string $path): ?RouteMatch`
  - `Router::routes(): Route[]`

- [ ] **Step 1: Write failing test**

```php
<?php // tests/RouterTest.php
namespace Falco\Tests;

use Falco\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testExactMatch(): void
    {
        $router = new Router();
        $handler = fn() => 'ok';
        $router->add('GET', '/items', $handler);
        $match = $router->match('GET', '/items');
        $this->assertNotNull($match);
        $this->assertSame([], $match->pathParams);
    }

    public function testTemplateMatch(): void
    {
        $router = new Router();
        $router->add('GET', '/items/{item_id}', fn() => null);
        $match = $router->match('GET', '/items/42');
        $this->assertSame(['item_id' => '42'], $match->pathParams);
    }

    public function testNoMatch(): void
    {
        $router = new Router();
        $router->add('GET', '/items', fn() => null);
        $this->assertNull($router->match('GET', '/nope'));
        $this->assertNull($router->match('POST', '/items'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  Run: `php vendor/bin/phpunit --filter RouterTest`
  Expected: FAIL — class not found.

- [ ] **Step 3: Implement Route, RouteMatch, Router**

```php
<?php // src/Route.php
namespace Falco;

final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly callable $handler,
        public readonly ?string $responseModel = null,
    ) {}
}
```

```php
<?php // src/RouteMatch.php
namespace Falco;

final class RouteMatch
{
    public function __construct(
        public readonly Route $route,
        public readonly array $pathParams,
    ) {}
}
```

```php
<?php // src/Router.php
namespace Falco;

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, ?string $responseModel = null): void
    {
        $this->routes[] = new Route($method, $path, $handler, $responseModel);
    }

    public function match(string $method, string $path): ?RouteMatch
    {
        foreach ($this->routes as $route) {
            if ($route->method !== $method) continue;
            $params = $this->matchTemplate($route->path, $path);
            if ($params !== null) {
                return new RouteMatch($route, $params);
            }
        }
        return null;
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes;
    }

    private function matchTemplate(string $template, string $path): ?array
    {
        $parts = preg_split('/\{(\w+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '^';
        for ($i = 0; $i < count($parts); $i += 2) {
            $regex .= preg_quote($parts[$i], '#');
            if (isset($parts[$i + 1])) {
                $regex .= '(?P<' . $parts[$i + 1] . '>[^/]+)';
            }
        }
        $regex .= '$';
        if (!preg_match('#' . $regex . '#', $path, $matches)) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) $params[$key] = $value;
        }
        return $params;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**
  Run: `php vendor/bin/phpunit --filter RouterTest`
  Expected: PASS (3 tests).

- [ ] **Step 5: Commit**
```bash
git add src/Route.php src/RouteMatch.php src/Router.php tests/RouterTest.php
git commit -m "feat: router with path template matching"
```

---

### Task 4: Parameter resolution + dependency injection

**Files:**
- Create: `src/Params/Path.php`, `src/Params/Query.php`, `src/Params/Body.php`, `src/Params/Header.php`, `src/Params/Depends.php`, `src/Params/ParamResolver.php`, `src/Params/DependencyContainer.php`
- Test: `tests/ParamResolverTest.php`

**Interfaces:**
- Consumes: `Request`, `Response`, `Model`, `Validator`, `ValidationException` (Tasks 1–2).
- Produces:
  - Attribute classes (each `#[Attribute(\Attribute::TARGET_PARAMETER)]`):
    - `Path::__construct(?string $alias = null)`, `Query::__construct(?string $alias = null)`, `Body::__construct(?string $alias = null)`, `Header::__construct(?string $alias = null)`
    - `Depends::__construct(string|array|null $callable = null)`
  - `ParamResolver::resolve(callable $handler, Request $request, array $pathParams): array`
  - `DependencyContainer::resolve(\ReflectionParameter $param, Request $request): mixed` (per-request cache)

- [ ] **Step 1: Write failing test**

```php
<?php // tests/ParamResolverTest.php
namespace Falco\Tests;

use Falco\Request;
use Falco\Params\Path;
use Falco\Params\Query;
use Falco\Params\Body;
use Falco\Params\Depends;
use Falco\Params\ParamResolver;
use Falco\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class Database
{
    public function __construct(public string $dsn = 'sqlite::memory:') {}
}

final class ParamResolverTest extends TestCase
{
    public function testPathQueryAndBody(): void
    {
        $resolver = new ParamResolver();
        $req = new Request('POST', '/items/7', ['q' => 'abc'], [], ['name' => 'Widget', 'price' => 1.5]);
        $handler = function (#[Path] int $item_id, #[Query] string $q = 'd', #[Body] Item $item): array {
            return [$item_id, $q, $item->name];
        };
        $args = $resolver->resolve($handler, $req, ['item_id' => '7']);
        $this->assertSame([7, 'abc', 'Widget'], $args);
    }

    public function testMissingQueryUsesDefault(): void
    {
        $resolver = new ParamResolver();
        $req = new Request('GET', '/', [], [], []);
        $handler = function (#[Query] int $limit = 10): int { return $limit; };
        $this->assertSame([10], $resolver->resolve($handler, $req, []));
    }

    public function testRequiredQueryMissingThrows(): void
    {
        $this->expectException(ValidationException::class);
        $resolver = new ParamResolver();
        $req = new Request('GET', '/', [], [], []);
        $handler = function (#[Query] int $limit): int { return $limit; };
        $resolver->resolve($handler, $req, []);
    }

    public function testDependencyInjection(): void
    {
        $resolver = new ParamResolver();
        $req = new Request('GET', '/', [], [], []);
        $handler = function (#[Depends] Database $db): string { return $db->dsn; };
        $this->assertSame(['sqlite::memory:'], $resolver->resolve($handler, $req, []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  Run: `php vendor/bin/phpunit --filter ParamResolverTest`
  Expected: FAIL — classes not found (also `Item` exists from ModelTest, fine).

- [ ] **Step 3: Implement the attributes**

```php
<?php // src/Params/Path.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Path
{
    public function __construct(public readonly ?string $alias = null) {}
}
```

`Query.php`, `Body.php`, `Header.php` are identical except the class name. `Depends.php`:

```php
<?php // src/Params/Depends.php
namespace Falco\Params;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Depends
{
    public function __construct(public readonly string|array|null $callable = null) {}
}
```

- [ ] **Step 4: Implement DependencyContainer and ParamResolver**

```php
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
```

```php
<?php // src/Params/ParamResolver.php
namespace Falco\Params;

use Falco\Model;
use Falco\Request;
use Falco\Response;
use Falco\Validation\Validator;
use Falco\Validation\ValidationException;

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
        $queryKey = $param->getAttributes(Query::class)[0]->newInstance()->alias ?? $name;
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
```

- [ ] **Step 5: Run test to verify it passes**
  Run: `php vendor/bin/phpunit --filter ParamResolverTest`
  Expected: PASS (4 tests).

- [ ] **Step 6: Commit**
```bash
git add src/Params/ tests/ParamResolverTest.php
git commit -m "feat: reflection-based param resolution and dependency injection"
```

---

### Task 5: App facade — routing, dispatch, error mapping

**Files:**
- Create: `src/App.php`
- Test: `tests/AppTest.php`

**Interfaces:**
- Consumes: `Router`, `ParamResolver`, `Validator`, `Request`, `Response`, `Model`, `HttpException`, `ValidationException`.
- Produces:
  - `App::__construct(string $title = 'Falco', string $version = '0.1.0', bool $docs = true, bool $debug = false)`
  - `App::get/post/put/patch/delete(string $path, callable $handler, ?string $responseModel = null): void`
  - `App::handle(Request $request): Response`
  - `App::routes(): Route[]`
  - `App::serve(Runtime\RuntimeInterface $runtime): never`
  - Properties: `title`, `version`, `debug`, `docs`

- [ ] **Step 1: Write failing test**

```php
<?php // tests/AppTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\Request;
use Falco\HttpException;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App(title: 'Test API', version: '1.0', docs: false);
    }

    public function testGetRoute(): void
    {
        $this->app->get('/hello', fn() => ['msg' => 'hi']);
        $res = $this->app->handle(new Request('GET', '/hello', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertSame(['msg' => 'hi'], $res->body);
    }

    public function testPathParamCoercion(): void
    {
        $this->app->get('/items/{item_id}', fn(int $item_id) => ['id' => $item_id]);
        $res = $this->app->handle(new Request('GET', '/items/7', [], [], []));
        $this->assertSame(['id' => 7], $res->body);
    }

    public function testNotFound(): void
    {
        $res = $this->app->handle(new Request('GET', '/missing', [], [], []));
        $this->assertSame(404, $res->status);
        $this->assertSame(['detail' => 'Not Found'], $res->body);
    }

    public function testValidationError422(): void
    {
        $this->app->post('/items', function (#[\Falco\Params\Body] Item $item) { return $item; });
        $res = $this->app->handle(new Request('POST', '/items', [], [], ['name' => 'x']));
        $this->assertSame(422, $res->status);
        $this->assertSame('missing', $res->body['detail'][0]['type']);
    }

    public function testHttpException(): void
    {
        $this->app->get('/secret', function () { throw new HttpException(403, 'Forbidden'); });
        $res = $this->app->handle(new Request('GET', '/secret', [], [], []));
        $this->assertSame(403, $res->status);
        $this->assertSame(['detail' => 'Forbidden'], $res->body);
    }

    public function testResponsePassThrough(): void
    {
        $this->app->get('/raw', fn() => \Falco\Response::json(['a' => 1], 201));
        $res = $this->app->handle(new Request('GET', '/raw', [], [], []));
        $this->assertSame(201, $res->status);
    }

    public function testModelReturnSerialized(): void
    {
        $this->app->get('/item', fn(): Item => Item::fromArray(['name' => 'W', 'price' => 2]));
        $res = $this->app->handle(new Request('GET', '/item', [], [], []));
        $this->assertSame(['name' => 'W', 'price' => 2.0], $res->body);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  Run: `php vendor/bin/phpunit --filter AppTest`
  Expected: FAIL — `App` class not found.

- [ ] **Step 3: Implement App**

```php
<?php // src/App.php
namespace Falco;

use Falco\Params\ParamResolver;
use Falco\Validation\ValidationException;

final class App
{
    private Router $router;
    private ParamResolver $resolver;

    public function __construct(
        public string $title = 'Falco',
        public string $version = '0.1.0',
        public bool $docs = true,
        public bool $debug = false,
    ) {
        $this->router = new Router();
        $this->resolver = new ParamResolver();
    }

    public function get(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('GET', $path, $handler, $responseModel); }

    public function post(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('POST', $path, $handler, $responseModel); }

    public function put(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('PUT', $path, $handler, $responseModel); }

    public function patch(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('PATCH', $path, $handler, $responseModel); }

    public function delete(string $path, callable $handler, ?string $responseModel = null): void
    { $this->router->add('DELETE', $path, $handler, $responseModel); }

    public function handle(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return Response::json(['detail' => 'Not Found'], 404);
        }
        try {
            $args = $this->resolver->resolve($match->route->handler, $request, $match->pathParams);
            $result = ($match->route->handler)(...$args);
        } catch (ValidationException $e) {
            return Response::json(['detail' => $e->errors], 422);
        } catch (HttpException $e) {
            return Response::json(['detail' => $e->getMessage()], $e->statusCode);
        } catch (\Throwable $e) {
            return Response::json(['detail' => $this->debug ? $e->getMessage() : 'Internal Server Error'], 500);
        }
        if ($result instanceof Response) return $result;
        if ($result instanceof Model) $result = $result->toArray();
        return Response::json($result);
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->router->routes();
    }
}
```

> Docs routes (`/docs`, `/openapi.json`) are registered in Task 6 when `OpenAPI\DocsController` exists; the `docs` flag is passed through then. Keep `docs` param now, wire in Task 6.

- [ ] **Step 4: Run test to verify it passes**
  Run: `php vendor/bin/phpunit --filter AppTest`
  Expected: PASS (7 tests).

- [ ] **Step 5: Commit**
```bash
git add src/App.php tests/AppTest.php
git commit -m "feat: app facade with dispatch and error mapping"
```

---

### Task 6: OpenAPI generation + docs endpoints

**Files:**
- Create: `src/OpenAPI/SchemaBuilder.php`, `src/OpenAPI/OpenApiGenerator.php`, `src/OpenAPI/DocsController.php`
- Modify: `src/App.php` (constructor registers docs routes)
- Test: `tests/OpenApiTest.php`

**Interfaces:**
- Consumes: `App`, `Route`, `Response`, `Model`, `Request`.
- Produces:
  - `SchemaBuilder::fromType(?\ReflectionType): array`, `fromModel(string $class): array`
  - `OpenApiGenerator::generate(App $app): array`
  - `DocsController::__construct(App $app)`, `DocsController::openapi(): Response`, `DocsController::docs(): Response`

- [ ] **Step 1: Write failing test**

```php
<?php // tests/OpenApiTest.php
namespace Falco\Tests;

use Falco\App;
use Falco\OpenAPI\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

final class OpenApiTest extends TestCase
{
    public function testGeneratesOpenApi(): void
    {
        $app = new App(title: 'Items', version: '2.0', docs: false);
        $app->get('/items/{item_id}', function (int $item_id, ?string $q = null): Item {
            return Item::fromArray(['name' => 'x', 'price' => 1]);
        }, responseModel: Item::class);
        $doc = (new OpenApiGenerator())->generate($app);
        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Items', $doc['info']['title']);
        $this->assertArrayHasKey('/items/{item_id}', $doc['paths']);
        $op = $doc['paths']['/items/{item_id}']['get'];
        $this->assertSame('path', $op['parameters'][0]['in']);
        $this->assertSame('query', $op['parameters'][1]['in']);
        $this->assertArrayHasKey('Item', $doc['components']['schemas']);
    }

    public function testDocsEndpoints(): void
    {
        $app = new App(docs: true);
        $this->assertSame(200, $app->handle(new \Falco\Request('GET', '/openapi.json', [], [], []))->status);
        $this->assertSame(200, $app->handle(new \Falco\Request('GET', '/docs', [], [], []))->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  Run: `php vendor/bin/phpunit --filter OpenApiTest`
  Expected: FAIL — `OpenApiGenerator` not found.

- [ ] **Step 3: Implement SchemaBuilder**

```php
<?php // src/OpenAPI/SchemaBuilder.php
namespace Falco\OpenAPI;

use Falco\Model;

final class SchemaBuilder
{
    public function fromType(?\ReflectionType $type): array
    {
        if ($type === null) return [];
        if ($type instanceof \ReflectionUnionType) {
            $schemas = array_map(fn($t) => $this->fromNamed($t), $type->getTypes());
            return ['anyOf' => array_values(array_filter($schemas, fn($s) => $s !== []))];
        }
        return $this->fromNamed($type);
    }

    public function fromNamed(\ReflectionNamedType $type): array
    {
        $name = $type->getName();
        $schema = match ($name) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            default => $this->named($name),
        };
        if ($type->allowsNull() && $schema !== []) {
            $schema['nullable'] = true;
        }
        return $schema;
    }

    private function named(string $name): array
    {
        if (is_subclass_of($name, Model::class)) {
            return ['$ref' => '#/components/schemas/' . $name];
        }
        if (is_subclass_of($name, \BackedEnum::class)) {
            return ['type' => 'string', 'enum' => array_map(fn($c) => $c->value, $name::cases())];
        }
        return [];
    }

    public function fromModel(string $class): array
    {
        $properties = [];
        $required = [];
        $ref = new \ReflectionClass($class);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $properties[$prop->getName()] = $this->fromType($prop->getType());
            if (!$prop->hasDefaultValue()) {
                $required[] = $prop->getName();
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required) $schema['required'] = $required;
        return $schema;
    }
}
```

- [ ] **Step 4: Implement OpenApiGenerator**

```php
<?php // src/OpenAPI/OpenApiGenerator.php
namespace Falco\OpenAPI;

use Falco\App;
use Falco\Model;
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
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName && is_subclass_of($typeName, Model::class)) {
                $schemas[$typeName] ??= $builder->fromModel($typeName);
                $requestBody = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $typeName]]],
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
            $schemas[$route->responseModel] ??= $builder->fromModel($route->responseModel);
            $content = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $route->responseModel]]];
        }
        $responses = ['200' => ['description' => 'Successful Response', 'content' => $content]];
        $responses['422'] = ['description' => 'Validation Error'];
        return $responses;
    }
}
```

- [ ] **Step 5: Implement DocsController and wire into App**

```php
<?php // src/OpenAPI/DocsController.php
namespace Falco\OpenAPI;

use Falco\App;
use Falco\Response;

final class DocsController
{
    public function __construct(private App $app) {}

    public function openapi(): Response
    {
        return Response::json((new OpenApiGenerator())->generate($this->app));
    }

    public function docs(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><title>{TITLE} - Swagger UI</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>window.onload=()=>SwaggerUIBundle({url:'/openapi.json',dom_id:'#swagger-ui'})</script>
</body>
</html>
HTML;
        return new Response(200, ['content-type' => 'text/html'], str_replace('{TITLE}', htmlspecialchars($this->app->title), $html));
    }
}
```

In `src/App.php` constructor, after `$this->resolver = new ParamResolver();` add:

```php
if ($this->docs) {
    $docs = new OpenAPI\DocsController($this);
    $this->get('/openapi.json', fn() => $docs->openapi());
    $this->get('/docs', fn() => $docs->docs());
}
```

- [ ] **Step 6: Run test to verify it passes**
  Run: `php vendor/bin/phpunit`
  Expected: PASS (all tests, including the 2 new OpenAPI tests).

- [ ] **Step 7: Commit**
```bash
git add src/OpenAPI/ src/App.php tests/OpenApiTest.php
git commit -m "feat: automatic OpenAPI 3.1 generation and swagger docs"
```

---

### Task 7: Runtimes — CLI, dev server, Swoole async adapter

**Files:**
- Create: `src/Runtime/RuntimeInterface.php`, `src/Runtime/SwooleRuntime.php`, `bin/server.php`, `bin/falco`
- Modify: `src/App.php` (`serve()` method)
- Test: `tests/SwooleRuntimeTest.php` (guard only)

**Interfaces:**
- Consumes: `App`, `Request`, `Response`.
- Produces:
  - `RuntimeInterface::serve(App $app): never`
  - `SwooleRuntime::__construct(string $host = '0.0.0.0', int $port = 8000)`
  - `App::serve(RuntimeInterface $runtime): never` — delegates to `$runtime->serve($this)`
  - `bin/falco serve <app.php> [--host=] [--port=] [--swoole]`
  - `bin/server.php` — `php -S` router script that boots the app per request via `FALCO_APP` env var.

- [ ] **Step 1: Write the guard test**

```php
<?php // tests/SwooleRuntimeTest.php
namespace Falco\Tests;

use Falco\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;

final class SwooleRuntimeTest extends TestCase
{
    public function testRequiresSwooleExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SwooleRuntime())->serve(new \Falco\App(docs: false));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  Run: `php vendor/bin/phpunit --filter SwooleRuntimeTest`
  Expected: FAIL — class not found.

- [ ] **Step 3: Implement the runtime files**

```php
<?php // src/Runtime/RuntimeInterface.php
namespace Falco\Runtime;

use Falco\App;

interface RuntimeInterface
{
    public function serve(App $app): never;
}
```

```php
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
```

```php
<?php // bin/server.php
<?php
// PHP built-in server router. Run via: php -S localhost:8000 bin/server.php
// The app file path is passed through the FALCO_APP environment variable.
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require getenv('FALCO_APP');
if (!$app instanceof \Falco\App) {
    http_response_code(500);
    echo 'FALCO_APP must point to a file returning a Falco\App instance';
    return true;
}
$app->handle(\Falco\Request::fromGlobals())->send();
return true;
```

```php
#!/usr/bin/env php
<?php // bin/falco
require dirname(__DIR__) . '/vendor/autoload.php';

$command = $argv[1] ?? 'help';
$appFile = $argv[2] ?? null;
$host = '127.0.0.1';
$port = 8000;
$useSwoole = false;

foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--host=')) $host = substr($arg, 7);
    if (str_starts_with($arg, '--port=')) $port = (int) substr($arg, 7);
    if ($arg === '--swoole') $useSwoole = true;
}

if ($command !== 'serve' || !$appFile || !is_file($appFile)) {
    fwrite(STDERR, "Usage: falco serve <app.php> [--host=HOST] [--port=PORT] [--swoole]\n");
    exit(1);
}

if ($useSwoole) {
    $app = require $appFile;
    (new \Falco\Runtime\SwooleRuntime($host, $port))->serve($app);
}
passthru(sprintf(
    'FALCO_APP=%s %s -S %s:%d %s',
    escapeshellarg($appFile),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($host),
    $port,
    escapeshellarg(dirname(__DIR__) . '/bin/server.php'),
), $exitCode);
exit($exitCode);
```

Add to `src/App.php`:

```php
public function serve(Runtime\RuntimeInterface $runtime): never
{
    $runtime->serve($this);
}
```

- [ ] **Step 4: Run tests to verify the guard passes**
  Run: `php vendor/bin/phpunit`
  Expected: PASS (all tests; `SwooleRuntimeTest` passes because swoole is not loaded).

- [ ] **Step 5: Commit**
```bash
chmod +x bin/falco
git add src/Runtime/ src/App.php bin/server.php bin/falco tests/SwooleRuntimeTest.php
git commit -m "feat: runtime abstraction with swoole adapter and falco CLI"
```

---

### Task 8: Example app + README

**Files:**
- Create: `examples/items/app.php`, `README.md`

**Interfaces:**
- Consumes: `App`, `Model`, `Params\Depends`, `Params\Query`, `HttpException`.

- [ ] **Step 1: Write the example app**

```php
<?php // examples/items/app.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Falco\App;
use Falco\Model;
use Falco\Params\Depends;
use Falco\Params\Query;
use Falco\HttpException;

final class Item extends Model
{
    public int $id;
    public string $name;
    public float $price;
    public ?string $description = null;
}

final class ItemCreate extends Model
{
    public string $name;
    public float $price;
    public ?string $description = null;
}

final class MemoryStore
{
    private array $items = [];
    public function nextId(): int { return count($this->items) + 1; }
    public function save(Item $item): void { $this->items[$item->id] = $item; }
    public function get(int $id): ?Item { return $this->items[$id] ?? null; }
    public function all(): array { return array_values($this->items); }
}

$app = new App(title: 'Items API', version: '1.0.0');

$app->get('/items', function (#[Depends] MemoryStore $store, #[Query] int $limit = 10): array {
    return array_slice(array_map(fn($i) => $i->toArray(), $store->all()), 0, $limit);
});

$app->get('/items/{item_id}', function (#[Depends] MemoryStore $store, int $item_id): Item {
    $item = $store->get($item_id);
    if ($item === null) throw new HttpException(404, 'Item not found');
    return $item;
});

$app->post('/items', function (#[Depends] MemoryStore $store, ItemCreate $body): Item {
    $item = Item::fromArray([...$body->toArray(), 'id' => $store->nextId()]);
    $store->save($item);
    return $item;
});

return $app;
```

- [ ] **Step 2: Write README.md** (concise: install, hello-world snippet, run `php bin/falco serve examples/items/app.php`, docs at `/docs`, feature list, Swoole note).

- [ ] **Step 3: Commit**
```bash
git add examples/items/app.php README.md
git commit -m "docs: example app and readme"
```

---

### Task 9: Final verification

- [ ] **Step 1: Run full test suite**
  Run: `php vendor/bin/phpunit`
  Expected: PASS (all tests).

- [ ] **Step 2: Smoke-test the dev server**
  Run:
```bash
FALCO_APP="$(pwd)/examples/items/app.php" php -S 127.0.0.1:8123 bin/server.php &
sleep 1
curl -s localhost:8123/openapi.json | head -c 200; echo
curl -s -X POST localhost:8123/items -H 'Content-Type: application/json' -d '{"name":"Widget","price":9.99}'
curl -s localhost:8123/items/1
kill %1
```
  Expected: JSON responses; item created and fetched; `/openapi.json` is valid 3.1.0.

- [ ] **Step 3: Run CLI smoke test**
  Run: `php bin/falco serve examples/items/app.php --port 8124` (background), then curl `/docs` → 200 HTML. Kill it.

- [ ] **Step 4: Final commit if any drift, then report**

---

## Self-review notes

- **Spec coverage:** All 5 requested features mapped — routing/params/validation (Tasks 2–5), OpenAPI docs (Task 6), response models/serialization (Task 5 `responseModel` + Task 6), DI (Task 4), async Swoole (Task 7). Naming: **Falco**.
- **Known ceilings (marked `ponytail:` in code):** `#[Depends]` autowires ctor params via defaults only (no request-sourced ctor args); `response_model` affects OpenAPI schema but not response-time validation; SwooleRuntime behavior untestable locally without the extension; `/redoc` skipped.
- **Skipped (add when asked):** middleware, background tasks, websockets, `@app.get` decorator-style route attributes (using `$app->get()` instead), per-field constraint attributes (`#[Field(min_length:…)]`) — validator does type/nullability only.

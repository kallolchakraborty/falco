<?php // tests/ParamResolverTest.php
namespace Falco\Tests;

use Falco\Request;
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
        $handler = function (int $item_id, #[Body] Item $item, #[Query] string $q = 'd'): array {
            return [$item_id, $q, $item->name];
        };
        $args = $resolver->resolve($handler, $req, ['item_id' => '7']);
        $this->assertSame(7, $args['item_id']);
        $this->assertSame('abc', $args['q']);
        $this->assertSame('Widget', $args['item']->name);
    }

    public function testMissingQueryUsesDefault(): void
    {
        $resolver = new ParamResolver();
        $req = new Request('GET', '/', [], [], []);
        $handler = function (#[Query] int $limit = 10): int { return $limit; };
        $args = $resolver->resolve($handler, $req, []);
        $this->assertSame(10, $args['limit']);
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
        $args = $resolver->resolve($handler, $req, []);
        $this->assertSame('sqlite::memory:', $args['db']->dsn);
    }

    public function testUnannotatedScalarIsQuery(): void
    {
        $resolver = new ParamResolver();
        $req = new Request('GET', '/', ['q' => 'hello'], [], []);
        $handler = function (string $q): string { return $q; };
        $this->assertSame(['q' => 'hello'], $resolver->resolve($handler, $req, []));
    }
}

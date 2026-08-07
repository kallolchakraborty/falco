<?php // tests/ValidatorTest.php
namespace Falco\Tests;

use Falco\Validation\Validator;
use Falco\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

enum Status: string { case Active = 'active'; case Closed = 'closed'; }

final class Types
{
    public int $int;
    public ?string $nullable;
    public Status $status;
}

final class ValidatorTest extends TestCase
{
    private function type(string $prop): \ReflectionNamedType
    {
        return (new \ReflectionProperty(Types::class, $prop))->getType();
    }

    public function testCoerceIntFromString(): void
    {
        $v = new Validator();
        $this->assertSame(5, $v->coerce('5', $this->type('int'), ['query', 'x']));
    }

    public function testRejectsBadInt(): void
    {
        $this->expectException(ValidationException::class);
        (new Validator())->coerce('abc', $this->type('int'), ['query', 'x']);
    }

    public function testNullable(): void
    {
        $v = new Validator();
        $this->assertNull($v->coerce(null, $this->type('nullable'), ['query', 'x']));
    }

    public function testEnum(): void
    {
        $v = new Validator();
        $this->assertSame(Status::Active, $v->coerce('active', $this->type('status'), ['query', 's']));
    }
}

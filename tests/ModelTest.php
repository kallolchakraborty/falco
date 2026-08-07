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

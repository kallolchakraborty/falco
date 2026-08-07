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
<?php // src/Metrics/Counter.php
namespace Falco\Metrics;

final class Counter
{
    private array $values = [];
    private float $total = 0;

    public function __construct(private string $name, private string $help) {}

    public function inc(array $labels = []): void
    {
        $this->total += 1;
        $key = $this->key($labels);
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;
    }

    public function labelKeys(): array { return array_keys($this->values); }
    public function labelsFor(string $key): array { return json_decode($key, true) ?? []; }
    public function total(): float { return $this->total; }
    public function name(): string { return $this->name; }
    public function help(): string { return $this->help; }
    public function values(): array { return $this->values; }

    private function key(array $labels): string
    {
        ksort($labels);
        return json_encode($labels);
    }
}

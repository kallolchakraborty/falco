<?php // src/Metrics/Histogram.php
namespace Falco\Metrics;

final class Histogram
{
    private array $buckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5];
    private array $counts = [];
    private float $sum = 0;
    private int $count = 0;

    public function __construct(private string $name, private string $help) {}

    public function observe(float $seconds, array $labels = []): void
    {
        $this->sum += $seconds;
        $this->count += 1;
        $key = $this->key($labels);
        $this->counts[$key] = ($this->counts[$key] ?? []);
        foreach ($this->buckets as $bucket) {
            if (!isset($this->counts[$key][$bucket])) {
                $this->counts[$key][$bucket] = 0;
            }
            if ($seconds <= $bucket) {
                $this->counts[$key][$bucket] += 1;
            }
        }
    }

    public function buckets(): array { return $this->buckets; }
    public function counts(): array { return $this->counts; }
    public function sum(): float { return $this->sum; }
    public function count(): int { return $this->count; }
    public function name(): string { return $this->name; }
    public function help(): string { return $this->help; }
    public function labelKeys(): array { return array_keys($this->counts); }
    public function labelsFor(string $key): array { return json_decode($key, true) ?? []; }

    private function key(array $labels): string
    {
        ksort($labels);
        return json_encode($labels);
    }
}
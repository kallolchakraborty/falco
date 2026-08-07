<?php // src/Metrics/Histogram.php
namespace Falco\Metrics;

final class Histogram
{
    private array $buckets = ['0.005', '0.01', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '2.5', '5'];
    /** @var array<string, array<string, int>> $counts — labelKey -> bucket -> count */
    private array $counts = [];
    /** @var array<string, float> $sums — labelKey -> sum */
    private array $sums = [];
    /** @var array<string, int> $countsTotal — labelKey -> count */
    private array $countsTotal = [];

    public function __construct(private string $name, private string $help) {}

    public function observe(float $seconds, array $labels = []): void
    {
        $key = $this->key($labels);
        $this->sums[$key] = ($this->sums[$key] ?? 0) + $seconds;
        $this->countsTotal[$key] = ($this->countsTotal[$key] ?? 0) + 1;
        if (!isset($this->counts[$key])) {
            $this->counts[$key] = array_fill_keys($this->buckets, 0);
        }
        foreach ($this->buckets as $bucket) {
            if ($seconds <= (float)$bucket) {
                $this->counts[$key][$bucket] += 1;
            }
        }
    }

    public function buckets(): array { return $this->buckets; }
    public function counts(): array { return $this->counts; }
    public function sums(): array { return $this->sums; }
    public function countsTotal(): array { return $this->countsTotal; }
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

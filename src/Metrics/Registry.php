<?php // src/Metrics/Registry.php
namespace Falco\Metrics;

/** Holds the set of Counter/Histogram metrics; `all()` feeds the Prometheus formatter. */
final class Registry
{
    private array $metrics = [];

    public function counter(string $name, string $help): Counter
    {
        $m = new Counter($name, $help);
        $this->metrics[] = $m;
        return $m;
    }

    public function histogram(string $name, string $help): Histogram
    {
        $m = new Histogram($name, $help);
        $this->metrics[] = $m;
        return $m;
    }

    /** @return (Counter|Histogram)[] */
    public function all(): array { return $this->metrics; }
}


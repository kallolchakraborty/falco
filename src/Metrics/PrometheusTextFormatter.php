<?php // src/Metrics/PrometheusTextFormatter.php
namespace Falco\Metrics;

final class PrometheusTextFormatter
{
    public function format(Registry $registry): string
    {
        $output = '';
        foreach ($registry->all() as $metric) {
            if ($metric instanceof Counter) {
                $output .= $this->formatCounter($metric);
            } elseif ($metric instanceof Histogram) {
                $output .= $this->formatHistogram($metric);
            }
        }
        return $output;
    }

    private function formatCounter(Counter $counter): string
    {
        $output = "# HELP {$counter->name()} {$counter->help()}\n";
        $output .= "# TYPE {$counter->name()} counter\n";
        foreach ($counter->values() as $key => $value) {
            $labels = $this->formatLabels($counter->labelsFor($key));
            $output .= "{$counter->name()}{$labels} {$value}\n";
        }
        return $output;
    }

    private function formatHistogram(Histogram $histogram): string
    {
        $output = "# HELP {$histogram->name()} {$histogram->help()}\n";
        $output .= "# TYPE {$histogram->name()} histogram\n";
        
        foreach ($histogram->counts() as $key => $counts) {
            $labels = $this->formatLabels($histogram->labelsFor($key));
            foreach ($histogram->buckets() as $bucket) {
                $count = $counts[$bucket] ?? 0;
                $bucketLabel = $this->formatBucketLabel($bucket);
                $output .= "{$histogram->name()}_bucket{$bucketLabel} {$count}\n";
            }
            $output .= "{$histogram->name()}_sum{$labels} {$histogram->sum()}\n";
            $output .= "{$histogram->name()}_count{$labels} {$histogram->count()}\n";
        }
        return $output;
    }

    private function formatLabels(array $labels): string
    {
        if (empty($labels)) return '';
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = "{$k}=\"{$v}\"";
        }
        return '{' . implode(',', $parts) . '}';
    }

    private function formatBucketLabel(float $bucket): string
    {
        return '{le="' . $bucket . '"}';
    }
}
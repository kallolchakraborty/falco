<?php
namespace Falco\Logging;

final class Logger implements LoggerInterface
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'error' => 400, 'critical' => 500];

    public function __construct(
        private mixed $stream = null,
        private string $minLevel = 'info',
    ) {
        if ($this->stream === null) {
            $this->stream = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 200) < self::LEVELS[$this->minLevel]) return;
        $record = [
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'message' => $message,
        ];
        foreach ($context as $k => $v) {
            $record[$k] = $this->stringify($v);
        }
        fwrite($this->stream, json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    private function stringify(mixed $v): mixed
    {
        if (is_scalar($v) || $v === null) return $v;
        if (is_array($v)) return array_map(fn ($x) => $this->stringify($x), $v);
        if ($v instanceof \JsonSerializable) return $this->stringify($v->jsonSerialize());
        if ($v instanceof \Stringable) return (string) $v;
        if (is_object($v)) return json_decode(json_encode($v), true);
        return (string) $v;
    }

    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }
}

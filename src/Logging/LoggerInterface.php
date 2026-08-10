<?php
namespace Falco\Logging;

/**
 * Minimal PSR-3-ish logger interface: log($level, $message, $context).
 * Context values are stringified by the concrete {@see Logger}.
 */
interface LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}

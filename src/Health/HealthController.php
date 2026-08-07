<?php // src/Health/HealthController.php
namespace Falco\Health;

use Falco\App;
use Falco\HttpException;

final class HealthController
{
    /** @param array<string, callable(): bool> $checks */
    public static function register(App $app, array $checks = []): void
    {
        $app->get('/health/live', fn (): array => ['status' => 'ok']);
        $app->get('/health/ready', function () use ($checks): array {
            $failed = [];
            foreach ($checks as $name => $fn) {
                try { if ($fn() !== true) $failed[] = $name; }
                catch (\Throwable) { $failed[] = $name; }
            }
            if ($failed) throw new HttpException(503, 'Not ready: ' . implode(', ', $failed));
            return ['status' => 'ok'];
        });
    }
}
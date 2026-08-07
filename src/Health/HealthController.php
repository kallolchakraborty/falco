<?php // src/Health/HealthController.php
namespace Falco\Health;

use Falco\App;
use Falco\HttpException;
use Falco\Response;

final class HealthController
{
    /** @param array<string, callable(): bool> $checks */
    public static function register(App $app, array $checks = []): void
    {
        $app->get('/health/live', fn (): array => ['status' => 'ok']);
        $app->get('/health/ready', function () use ($checks): Response {
            $failed = [];
            $checkDetails = [];
            foreach ($checks as $name => $fn) {
                try {
                    $ok = $fn() === true;
                    $checkDetails[] = ['name' => $name, 'ok' => $ok];
                    if (!$ok) $failed[] = $name;
                } catch (\Throwable) {
                    $checkDetails[] = ['name' => $name, 'ok' => false];
                    $failed[] = $name;
                }
            }
            if ($failed) {
                return Response::json([
                    'status' => 'failed',
                    'checks' => $checkDetails,
                ], 503);
            }
            return Response::json(['status' => 'ok']);
        });
    }
}

<?php // examples/items/app.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Falco\App;
use Falco\Config\Config;
use Falco\Data\Connection;
use Falco\Data\RefreshTokenRepository;
use Falco\Health\HealthController;
use Falco\HttpException;
use Falco\Logging\Logger;
use Falco\Metrics\MetricsMiddleware;
use Falco\Metrics\PrometheusTextFormatter;
use Falco\Metrics\Registry;
use Falco\Middleware\AuthMiddleware;
use Falco\Middleware\ErrorHandlerMiddleware;
use Falco\Middleware\RequestIdMiddleware;
use Falco\Middleware\RequestLoggingMiddleware;
use Falco\Response;
use Falco\Security\JwtService;

$cfg = new Config([
    'jwt_secret' => (string) (getenv('FALCO_JWT_SECRET') ?: ''),
    'metrics' => (string) (getenv('FALCO_METRICS') ?: '0'),
    'cors_origins' => array_filter(array_map('trim', explode(',', (string) (getenv('FALCO_CORS_ORIGINS') ?: '')))),
    'sqlite_path' => (string) (getenv('FALCO_SQLITE_PATH') ?: __DIR__ . '/data.sqlite'),
]);

if (strlen($cfg->get('jwt_secret')) < 32) {
    throw new \RuntimeException('FALCO_JWT_SECRET must be at least 32 chars');
}
$db = new Connection('sqlite:' . $cfg->get('sqlite_path'));
$migration = file_get_contents(__DIR__ . '/migrations/001_init.sql');
if ($migration !== false) {
    $db->pdo()->exec($migration);
}

$logger = new Logger();
$jwt = new JwtService($cfg->get('jwt_secret'));
$store = new RefreshTokenRepository($db);

$app = new App(title: 'Items API', version: '1.0');

$app->middleware(new RequestIdMiddleware());
$app->middleware(new RequestLoggingMiddleware($logger));
$app->middleware(new ErrorHandlerMiddleware(debug: (bool) $cfg->get('debug', false)));

$app->get('/health/live', fn(): array => ['status' => 'ok']);

use Falco\Params\Body;
use Falco\Params\Query;

$app->post('/login', function (#[Body] array $body) use ($db, $jwt, $store): array {
    $username = (string) ($body['username'] ?? '');
    $password = (string) ($body['password'] ?? '');
    $row = $db->query('SELECT id, password_hash FROM users WHERE username = ?', [$username])->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        throw new HttpException(401, 'Incorrect username or password');
    }
    $accessToken = $jwt->encode(['sub' => $row['id'], 'username' => $username], 900);
    $refreshToken = $store->issue((int) $row['id']);
    return ['access_token' => $accessToken, 'refresh_token' => $refreshToken, 'token_type' => 'bearer'];
});

$app->post('/refresh', function (#[Body] array $body) use ($db, $jwt, $store): array {
    $userId = $store->consume((string) ($body['refresh_token'] ?? ''));
    if ($userId === null) throw new HttpException(401, 'Invalid or expired refresh token');
    $row = $db->query('SELECT username FROM users WHERE id = ?', [$userId])->fetch();
    $accessToken = $jwt->encode(['sub' => $userId, 'username' => $row['username']], 900);
    $newRefreshToken = $store->issue($userId);
    return ['access_token' => $accessToken, 'refresh_token' => $newRefreshToken, 'token_type' => 'bearer'];
});

$auth = new AuthMiddleware($jwt, required: true);

$app->post('/items', function (array $body, \Falco\Security\JwtClaims $claims) use ($db): array {
    $db->exec(
        'INSERT INTO items (user_id, name, price, created_at) VALUES (?, ?, ?, ?)',
        [(int) $claims->get('sub'), (string) $body['name'], (float) $body['price'], time()],
    );
    $id = (int) $db->pdo()->lastInsertId();
    return ['id' => $id, 'name' => $body['name'], 'price' => (float) $body['price']];
}, null, ['middleware' => [$auth]]);

$app->get('/items', function (\Falco\Security\JwtClaims $claims) use ($db): array {
    return array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'price' => (float) $row['price']],
        $db->query('SELECT id, name, price FROM items WHERE user_id = ?', [(int) $claims->get('sub')])->fetchAll());
}, null, ['middleware' => [$auth]]);

$app->get('/items/{item_id}', function (\Falco\Security\JwtClaims $claims, int $item_id) use ($db): array {
    $row = $db->query('SELECT id, name, price FROM items WHERE id = ? AND user_id = ?', [$item_id, (int) $claims->get('sub')])->fetch();
    if (!$row) throw new HttpException(404, 'Item not found');
    return ['id' => (int) $row['id'], 'name' => $row['name'], 'price' => (float) $row['price']];
}, null, ['middleware' => [$auth]]);

$app->delete('/items/{item_id}', function (\Falco\Security\JwtClaims $claims, int $item_id) use ($db): array {
    $n = $db->exec('DELETE FROM items WHERE id = ? AND user_id = ?', [$item_id, (int) $claims->get('sub')]);
    if ($n === 0) throw new HttpException(404, 'Item not found');
    return ['ok' => true];
}, null, ['middleware' => [$auth]]);

if ($cfg->get('metrics') === '1') {
    $registry = new Registry();
    $app->middleware(new MetricsMiddleware($registry));
    $app->get('/metrics', fn (): Response => Response::text((new PrometheusTextFormatter())->format($registry)));
}

HealthController::register($app, ['db' => fn (): bool => (bool) @$db->query('SELECT 1')->fetch()]);

// seed demo user (dev only if FALCO_SEED_PASSWORD set)
$seedPass = (string) (getenv('FALCO_SEED_PASSWORD') ?: '');
if ($seedPass !== '' && !$db->query('SELECT 1 FROM users WHERE username = ?', ['admin'])->fetch()) {
    $db->exec('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)',
        ['admin', password_hash($seedPass, PASSWORD_DEFAULT), time()]);
}

return $app;

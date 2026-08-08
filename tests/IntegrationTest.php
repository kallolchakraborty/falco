<?php

namespace Falco\Tests;

use Falco\App;
use Falco\Data\Connection;
use Falco\Data\RefreshTokenRepository;
use Falco\Middleware\AuthMiddleware;
use Falco\Middleware\ErrorHandlerMiddleware;
use Falco\Middleware\RequestIdMiddleware;
use Falco\Params\Body;
use Falco\Request;
use Falco\Security\JwtService;
use Falco\Model;
use PHPUnit\Framework\TestCase;

final class UserRegister extends Model
{
    public string $username;
    public string $password;
}

final class UserLogin extends Model
{
    public string $username;
    public string $password;
}

final class IntegrationTest extends TestCase
{
    private App $app;
    private Connection $conn;
    private RefreshTokenRepository $tokenRepo;
    private JwtService $jwt;
    private string $jwtSecret = 'super-secret-jwt-key-at-least-32-bytes-long';

    protected function setUp(): void
    {
        $this->conn = new Connection('sqlite::memory:');

        $this->conn->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )
        ');
        $this->conn->exec('
            CREATE TABLE IF NOT EXISTS refresh_tokens (
                token_hash TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id),
                expires_at INTEGER NOT NULL,
                consumed_at INTEGER NULL
            )
        ');

        $this->tokenRepo = new RefreshTokenRepository($this->conn);
        $this->jwt = new JwtService($this->jwtSecret);

        $this->app = new App(title: 'Test API', version: '1.0.0', debug: true, docs: false);
        $this->app->middleware(new ErrorHandlerMiddleware(debug: true));
        $this->app->middleware(new RequestIdMiddleware());

        $jwt = $this->jwt;
        $tokenRepo = $this->tokenRepo;
        $db = $this->conn;

        $this->app->post('/register', function (
            #[Body] UserRegister $body
        ) use ($jwt, $tokenRepo, $db): array {
            $existing = $db->query('SELECT id FROM users WHERE username = ?', [$body->username])->fetch();
            if ($existing) {
                throw new \Falco\HttpException(409, 'Username already exists');
            }

            $passwordHash = password_hash($body->password, PASSWORD_ARGON2ID);
            $db->exec(
                'INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)',
                [$body->username, $passwordHash, time()]
            );
            $userId = (int) $db->pdo()->lastInsertId();

            $accessToken = $jwt->encode(['sub' => $userId, 'username' => $body->username], 900);
            $refreshToken = $tokenRepo->issue($userId);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
            ];
        });

        $this->app->post('/login', function (
            #[Body] UserLogin $body
        ) use ($jwt, $tokenRepo, $db): array {
            $user = $db->query('SELECT id, username, password_hash FROM users WHERE username = ?', [$body->username])->fetch();
            if (!$user || !password_verify($body->password, $user['password_hash'])) {
                throw new \Falco\HttpException(401, 'Invalid credentials');
            }

            $accessToken = $jwt->encode(['sub' => $user['id'], 'username' => $user['username']], 900);
            $refreshToken = $tokenRepo->issue((int) $user['id']);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
            ];
        });

        $this->app->post('/refresh', function (
            #[Body] array $body
        ) use ($jwt, $tokenRepo, $db): array {
            $refreshToken = $body['refresh_token'] ?? '';
            $userId = $tokenRepo->consume($refreshToken);
            if ($userId === null) {
                throw new \Falco\HttpException(401, 'Invalid or expired refresh token');
            }

            $user = $db->query('SELECT username FROM users WHERE id = ?', [$userId])->fetch();
            if (!$user) {
                throw new \Falco\HttpException(401, 'User not found');
            }

            $accessToken = $jwt->encode(['sub' => $userId, 'username' => $user['username']], 900);
            $newRefreshToken = $tokenRepo->issue($userId);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken,
                'token_type' => 'bearer',
            ];
        });

        $auth = new AuthMiddleware($this->jwt, required: true);
        $this->app->get('/me', fn (\Falco\Security\JwtClaims $claims): array => [
            'id' => $claims->get('sub'),
            'username' => $claims->get('username'),
        ], options: ['middleware' => [$auth]]);

        $this->app->post('/logout', function (\Falco\Security\JwtClaims $claims) use ($tokenRepo): void {
            $tokenRepo->revokeAll($claims->get('sub'));
        }, options: ['middleware' => [$auth]]);
    }

    public function testFullAuthFlow(): void
    {
        // Register
        $registerResponse = $this->app->handle(
            new Request('POST', '/register', [], [], ['username' => 'testuser', 'password' => 'testpass'])
        );
        $this->assertSame(200, $registerResponse->status);
        $registerData = $registerResponse->body;
        $this->assertArrayHasKey('access_token', $registerData);
        $this->assertArrayHasKey('refresh_token', $registerData);

        $accessToken = $registerData['access_token'];
        $refreshToken = $registerData['refresh_token'];

        // Access protected endpoint with access token
        $meResponse = $this->app->handle(
            new Request('GET', '/me', [], ['authorization' => "Bearer $accessToken"], [])
        );
        $this->assertSame(200, $meResponse->status);
        $meData = $meResponse->body;
        $this->assertSame('testuser', $meData['username']);

        // Refresh token
        $refreshResponse = $this->app->handle(
            new Request('POST', '/refresh', [], [], ['refresh_token' => $refreshToken])
        );
        $this->assertSame(200, $refreshResponse->status);
        $refreshData = $refreshResponse->body;
        $this->assertArrayHasKey('access_token', $refreshData);
        $this->assertArrayHasKey('refresh_token', $refreshData);

        // Old refresh token should be consumed
        $oldRefreshToken = $refreshToken;
        $refreshToken = $refreshData['refresh_token'];
        $accessToken = $refreshData['access_token'];

        $replayResponse = $this->app->handle(
            new Request('POST', '/refresh', [], [], ['refresh_token' => $oldRefreshToken])
        );
        $this->assertSame(401, $replayResponse->status);

        // New access token works
        $meResponse2 = $this->app->handle(
            new Request('GET', '/me', [], ['authorization' => "Bearer $accessToken"], [])
        );
        $this->assertSame(200, $meResponse2->status);

        // Logout revokes all tokens
        $logoutResponse = $this->app->handle(
            new Request('POST', '/logout', [], ['authorization' => "Bearer $accessToken"], [])
        );
        $this->assertSame(200, $logoutResponse->status);

        // Refresh token should be revoked
        $revokedResponse = $this->app->handle(
            new Request('POST', '/refresh', [], [], ['refresh_token' => $refreshToken])
        );
        $this->assertSame(401, $revokedResponse->status);
    }

    public function testLoginFlow(): void
    {
        // First register a user
        $this->app->handle(
            new Request('POST', '/register', [], [], ['username' => 'loginuser', 'password' => 'loginpass'])
        );

        // Login
        $loginResponse = $this->app->handle(
            new Request('POST', '/login', [], [], ['username' => 'loginuser', 'password' => 'loginpass'])
        );
        $this->assertSame(200, $loginResponse->status);
        $loginData = $loginResponse->body;
        $this->assertArrayHasKey('access_token', $loginData);
        $this->assertArrayHasKey('refresh_token', $loginData);

        // Wrong password
        $wrongPassResponse = $this->app->handle(
            new Request('POST', '/login', [], [], ['username' => 'loginuser', 'password' => 'wrongpass'])
        );
        $this->assertSame(401, $wrongPassResponse->status);
    }
}

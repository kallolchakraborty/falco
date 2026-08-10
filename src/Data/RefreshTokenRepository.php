<?php // src/Data/RefreshTokenRepository.php
namespace Falco\Data;

use Falco\Security\RefreshTokenStoreInterface;

/**
 * SQLite-backed {@see RefreshTokenStoreInterface}: stores SHA-256 hashes,
 * enforces single-use (`consumed_at`) and `expires_at`, supports per-user
 * revocation via {@see revokeAll()}.
 */
final class RefreshTokenRepository implements RefreshTokenStoreInterface
{
    public function __construct(private Connection $conn) {}

    public function issue(int $userId, int $ttlSeconds = 2592000): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->conn->exec(
            'INSERT INTO refresh_tokens (token_hash, user_id, expires_at, consumed_at) VALUES (?, ?, ?, NULL)',
            [hash('sha256', $token), $userId, time() + $ttlSeconds],
        );
        return $token;
    }

    public function consume(string $token): ?int
    {
        $hash = hash('sha256', $token);
        $row = $this->conn->query('SELECT user_id, consumed_at, expires_at FROM refresh_tokens WHERE token_hash = ?', [$hash])->fetch();
        if (!$row) return null;
        if ($row['consumed_at'] !== null) return null;
        if ((int) $row['expires_at'] < time()) return null;
        $this->conn->exec('UPDATE refresh_tokens SET consumed_at = ? WHERE token_hash = ?', [time(), $hash]);
        return (int) $row['user_id'];
    }

    public function revokeAll(int $userId): void
    {
        $this->conn->exec('UPDATE refresh_tokens SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL', [time(), $userId]);
    }
}


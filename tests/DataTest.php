<?php // tests/DataTest.php
namespace Falco\Tests;

use Falco\Data\Connection;
use Falco\Data\RefreshTokenRepository;
use PHPUnit\Framework\TestCase;

final class DataTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = new Connection('sqlite::memory:');
        $this->conn->exec('CREATE TABLE refresh_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            consumed_at INTEGER NULL
        )');
    }

    public function testQuery(): void
    {
        $this->conn->exec('INSERT INTO refresh_tokens (token_hash, user_id, expires_at, consumed_at) VALUES (?,?,?,NULL)', ['h', 1, time() + 100]);
        $count = $this->conn->query('SELECT COUNT(*) AS n FROM refresh_tokens')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $count['n']);
    }

    public function testIssueAndConsume(): void
    {
        $repo = new RefreshTokenRepository($this->conn);
        $token = $repo->issue(5);
        $this->assertSame(5, $repo->consume($token));
        $this->assertNull($repo->consume($token)); // replay rejected
    }

    public function testRevokeAll(): void
    {
        $repo = new RefreshTokenRepository($this->conn);
        $t1 = $repo->issue(3);
        $repo->issue(3);
        $repo->revokeAll(3);
        $this->assertNull($repo->consume($t1));
    }
}

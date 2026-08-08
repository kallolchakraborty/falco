<?php

namespace Falco\Tests;

use Falco\Security\JwtService;
use Falco\Security\JwtException;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtService('0123456789abcdef0123456789abcdef');
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $token = $this->jwt->encode(['sub' => '42', 'role' => 'admin'], 60);
        $payload = $this->jwt->decode($token);
        $this->assertSame('42', $payload['sub']);
        $this->assertSame('admin', $payload['role']);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = $this->jwt->encode(['sub' => '1'], 60);
        $bad = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');
        $this->expectException(JwtException::class);
        $this->jwt->decode($bad);
    }

    public function testExpiredRejected(): void
    {
        $token = $this->jwt->encode(['sub' => '1'], -10);
        $this->expectException(JwtException::class);
        $this->jwt->decode($token);
    }

    public function testShortSecretRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtService('short');
    }
}

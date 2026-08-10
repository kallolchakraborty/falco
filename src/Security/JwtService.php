<?php

namespace Falco\Security;

/**
 * HS256 JWT encode/decode (PSR-15-independent, no dependencies).
 * Secret must be >= 32 bytes. Signatures use `hash_equals`; payloads carry
 * `iat` and `exp`; tokens are base64url-encoded.
 */
final class JwtService
{
    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 bytes');
        }
        $this->secret = $secret;
    }

    public function encode(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $ttlSeconds]);
        $part = $this->b64(json_encode($header)) . '.' . $this->b64(json_encode($payload));
        return $part . '.' . $this->b64(hash_hmac('sha256', $part, $this->secret, true));
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new JwtException('invalid_token');
        [$head, $payload, $sig] = $parts;
        $expected = $this->b64(hash_hmac('sha256', $head . '.' . $payload, $this->secret, true));
        if (!hash_equals($expected, $sig)) throw new JwtException('invalid_signature');
        $claims = json_decode($this->unb64($payload), true);
        if (!is_array($claims)) throw new JwtException('invalid_token');
        if (($claims['exp'] ?? 0) < time()) throw new JwtException('expired');
        return $claims;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function unb64(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

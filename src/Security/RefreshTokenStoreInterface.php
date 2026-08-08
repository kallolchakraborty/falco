<?php

namespace Falco\Security;

interface RefreshTokenStoreInterface
{
    public function issue(int $userId, int $ttlSeconds = 2592000): string;
    public function consume(string $token): ?int;
    public function revokeAll(int $userId): void;
}

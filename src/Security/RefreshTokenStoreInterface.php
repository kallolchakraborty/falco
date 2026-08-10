<?php

namespace Falco\Security;

/**
 * Pluggable refresh-token store. `issue()` returns the raw token to the client
 * (only its hash is persisted); `consume()` validates and single-uses it,
 * returning the user id or null.
 */
interface RefreshTokenStoreInterface
{
    public function issue(int $userId, int $ttlSeconds = 2592000): string;
    public function consume(string $token): ?int;
    public function revokeAll(int $userId): void;
}

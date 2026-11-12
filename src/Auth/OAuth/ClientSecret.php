<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

/**
 * Client secrets of pre-registered OAuth clients (SPEC.md 7). config.php keeps only the hash.
 */
final class ClientSecret
{
    public const HASH_PREFIX = 'sha256:';

    /**
     * 32 random bytes, base64url without padding.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * The value for auth.oauth.clients[].secret_hash.
     */
    public static function hash(string $secret): string
    {
        return self::HASH_PREFIX . hash('sha256', $secret);
    }
}

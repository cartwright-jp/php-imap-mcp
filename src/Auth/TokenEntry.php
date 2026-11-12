<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * One accepted Bearer token from config.php: its SHA-256 hash and the account it may use (SPEC.md 7, 8).
 */
final class TokenEntry
{
    public const HASH_PREFIX = 'sha256:';

    public function __construct(
        /** Lowercase hex SHA-256 of the token, without the 'sha256:' prefix. */
        public readonly string $sha256,
        public readonly string $accountId,
        /** Free text for the audit log, e.g. 'claude.ai'. */
        public readonly string $label = '',
    ) {
    }

    public static function hashOf(string $token): string
    {
        return self::HASH_PREFIX . hash('sha256', $token);
    }
}

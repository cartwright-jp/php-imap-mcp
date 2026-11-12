<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * Checks an Authorization: Bearer header against the configured token hashes (SPEC.md 7).
 */
final class TokenAuthenticator
{
    /** RFC 6750 b64token. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9\-._~+\/]+=*$/';

    /**
     * @param list<TokenEntry> $tokens several tokens may be valid at once, so they can be rotated without downtime
     */
    public function __construct(private readonly array $tokens)
    {
    }

    /**
     * @throws AuthenticationFailed
     */
    public function authenticate(?string $authorization): TokenEntry
    {
        if ($authorization === null || trim($authorization) === '') {
            throw new AuthenticationFailed(AuthenticationFailed::MISSING);
        }
        if (
            preg_match('/^Bearer +(\S+)\s*$/i', $authorization, $m) !== 1
            || preg_match(self::TOKEN_PATTERN, $m[1]) !== 1
        ) {
            throw new AuthenticationFailed(AuthenticationFailed::MALFORMED);
        }

        $presented = hash('sha256', $m[1]);
        $match = null;
        // Compare against every entry so the time taken does not depend on which one matches.
        foreach ($this->tokens as $entry) {
            if (hash_equals($entry->sha256, $presented) && $match === null) {
                $match = $entry;
            }
        }
        if ($match === null) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID);
        }

        return $match;
    }

    /**
     * A new random token (32 bytes, base64url without padding) for bin/generate-token.php.
     */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

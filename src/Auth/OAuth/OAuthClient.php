<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

/**
 * A pre-registered OAuth client (config.php auth.oauth.clients, SPEC.md 7).
 */
final class OAuthClient
{
    /**
     * @param list<string> $redirectUris compared exactly (no normalisation)
     */
    public function __construct(
        public readonly string $id,
        /** Lowercase hex SHA-256 of the client secret; null for a public client (PKCE only). */
        public readonly ?string $secretSha256,
        public readonly array $redirectUris,
        public readonly string $name,
    ) {
    }

    public function isConfidential(): bool
    {
        return $this->secretSha256 !== null;
    }

    public function allowsRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }
}

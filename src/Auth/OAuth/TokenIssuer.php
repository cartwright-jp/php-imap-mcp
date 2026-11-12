<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Closure;

/**
 * Issues an access token and a refresh token for one grant (SPEC.md 7: sealed tokens; the refresh token is
 * extended by refresh_ttl_days every time it is used).
 */
final class TokenIssuer
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly OAuthSettings $settings,
        private readonly Sealer $sealer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * The token response body (RFC 6749 5.1).
     *
     * @return array<string, mixed>
     */
    public function issue(string $grantId, string $email, string $password, string $clientId): array
    {
        $now = ($this->clock)();
        $base = [
            'gid' => $grantId,
            'sub' => $email,
            'pwd' => $password,
            'cid' => $clientId,
            'aud' => $this->settings->resource,
            'scope' => OAuthSettings::SCOPE,
            'iat' => $now,
        ];

        return [
            'access_token' => $this->sealer->seal(
                Sealer::ACCESS_TOKEN,
                ['jti' => self::newId(), 'exp' => $now + $this->settings->accessTtlSec] + $base,
            ),
            'token_type' => 'Bearer',
            'expires_in' => $this->settings->accessTtlSec,
            'refresh_token' => $this->sealer->seal(
                Sealer::REFRESH_TOKEN,
                ['jti' => self::newId(), 'exp' => $now + $this->settings->refreshTtlSec()] + $base,
            ),
            'scope' => OAuthSettings::SCOPE,
        ];
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}

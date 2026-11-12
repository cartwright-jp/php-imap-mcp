<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Cartwright\ImapMcp\Auth\AuthenticationFailed;
use Cartwright\ImapMcp\Auth\BearerAuthenticator;
use Cartwright\ImapMcp\Auth\Principal;
use Closure;

/**
 * imap_oauth mode: validates the sealed access token of an MCP request (SPEC.md 7; MCP authorization:
 * audience validation, no token passthrough). The mail credentials come out of the token itself.
 */
final class AccessTokenAuthenticator implements BearerAuthenticator
{
    /** Claims every access token carries. */
    public const STRING_CLAIMS = ['jti', 'gid', 'sub', 'pwd', 'cid', 'aud', 'scope'];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly OAuthSettings $settings,
        private readonly Sealer $sealer,
        private readonly IdStore $ids,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function authenticate(?string $authorization): Principal
    {
        if ($authorization === null || trim($authorization) === '') {
            throw new AuthenticationFailed(AuthenticationFailed::MISSING);
        }
        if (preg_match('/^Bearer +(\S+)\s*$/i', $authorization, $m) !== 1) {
            throw new AuthenticationFailed(AuthenticationFailed::MALFORMED);
        }
        $claims = Claims::of($this->sealer->open(Sealer::ACCESS_TOKEN, $m[1]), self::STRING_CLAIMS);
        if ($claims === null) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID);
        }
        if ($claims->int('exp') <= ($this->clock)()) {
            throw new AuthenticationFailed(AuthenticationFailed::EXPIRED);
        }
        // Only tokens issued for this resource (RFC 8707); a token for another server is never accepted.
        if (!$this->settings->isOwnResource($claims->string('aud'))) {
            throw new AuthenticationFailed(AuthenticationFailed::AUDIENCE);
        }
        // A client removed from config.php loses access at once.
        if (!isset($this->settings->clients[$claims->string('cid')])) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID);
        }
        if ($this->ids->isMarked(IdStore::REVOKED_GRANTS, $claims->string('gid'))) {
            throw new AuthenticationFailed(AuthenticationFailed::REVOKED);
        }

        return new Principal(
            $this->settings->account($claims->string('sub'), $claims->string('pwd')),
            $claims->string('cid'),
            $claims->string('gid'),
        );
    }

    /**
     * RFC 6750 / RFC 9728: tells the client where to find the authorization server.
     */
    public function challenge(AuthenticationFailed $failure): string
    {
        $value = sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            $this->settings->protectedResourceMetadataUrl(),
            OAuthSettings::SCOPE,
        );
        if ($failure->reason !== AuthenticationFailed::MISSING) {
            $value .= ', error="invalid_token"';
        }

        return $value;
    }
}

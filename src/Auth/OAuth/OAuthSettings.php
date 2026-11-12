<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Cartwright\ImapMcp\Auth\Account;

/**
 * config.php auth.oauth (SPEC.md 7, 8).
 */
final class OAuthSettings
{
    /** The single scope: everything the enabled tools allow (tools.* still decides which tools exist). */
    public const SCOPE = 'mail';
    public const CODE_TTL_SEC = 300;
    public const LOGIN_FORM_TTL_SEC = 600;

    /**
     * @param list<string> $allowedEmailDomains lowercase
     * @param array<string, OAuthClient> $clients
     */
    public function __construct(
        /** The MCP endpoint URL as registered in the client, e.g. https://example.com/mcp/ (RFC 8707 resource). */
        public readonly string $resource,
        /** 32-byte key for Sealer. */
        public readonly string $key,
        public readonly string $imapHost,
        public readonly int $imapPort,
        public readonly string $imapEncryption,
        public readonly array $allowedEmailDomains,
        public readonly int $accessTtlSec,
        public readonly int $refreshTtlDays,
        public readonly array $clients,
        public readonly ?string $draftsFolder = null,
        public readonly ?string $sentFolder = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['resource' => $this->resource, 'key' => '***', 'clients' => array_keys($this->clients)];
    }

    /**
     * The authorization server's issuer identifier: the resource without the trailing slash
     * (e.g. https://example.com/mcp), so its well-known documents can live inside the MCP directory.
     */
    public function issuer(): string
    {
        return rtrim($this->resource, '/');
    }

    /**
     * An https resource must only be used over HTTPS; http is allowed for local testing only (SPEC.md 7).
     */
    public function requiresHttps(): bool
    {
        return strtolower((string) parse_url($this->resource, PHP_URL_SCHEME)) === 'https';
    }

    public function protectedResourceMetadataUrl(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource';
    }

    public function authorizationEndpoint(): string
    {
        return $this->issuer() . '/authorize';
    }

    public function tokenEndpoint(): string
    {
        return $this->issuer() . '/token';
    }

    /**
     * scheme://host[:port] of the issuer, the only Origin the login form accepts.
     */
    public function origin(): string
    {
        $parts = parse_url($this->resource);
        $origin = strtolower(($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? ''));

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }

    public function refreshTtlSec(): int
    {
        return $this->refreshTtlDays * 86400;
    }

    /**
     * Whether a client's resource parameter names this server. Scheme and host are case-insensitive and a
     * trailing slash is ignored (MCP: canonical URI, SHOULD accept uppercase scheme and host).
     */
    public function isOwnResource(string $resource): bool
    {
        return self::canonical($resource) === self::canonical($this->resource);
    }

    public function isEmailAllowed(string $email): bool
    {
        $at = strrpos($email, '@');

        return $at !== false && in_array(strtolower(substr($email, $at + 1)), $this->allowedEmailDomains, true);
    }

    public function account(string $email, string $password): Account
    {
        return new Account(
            $email,
            $email,
            $password,
            $this->imapHost,
            $this->imapPort,
            $this->imapEncryption,
            null,
            $this->draftsFolder,
            $this->sentFolder,
        );
    }

    private static function canonical(string $uri): string
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return "\0invalid";
        }
        $canonical = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $canonical .= ':' . $parts['port'];
        }
        $canonical .= rtrim($parts['path'] ?? '', '/');

        return isset($parts['query']) ? $canonical . '?' . $parts['query'] : $canonical;
    }
}

<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use Cartwright\ImapMcp\Auth\TokenEntry;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Config\ConfigParser;

/**
 * An imap_oauth config for tests. Example values only (CLAUDE.md security rules).
 */
final class OAuthTestConfig
{
    public const RESOURCE = 'https://example.com/mcp/';
    public const ISSUER = 'https://example.com/mcp';
    public const CLIENT = 'claude';
    public const CLIENT_SECRET = 'test-client-secret-0123456789abcdefghij';
    public const REDIRECT = 'https://client.example/api/mcp/auth_callback';
    public const PUBLIC_CLIENT = 'inspector';
    public const PUBLIC_REDIRECT = 'http://localhost:6274/oauth/callback';
    public const EMAIL = 'user@example.com';
    public const PASSWORD = 'right-password';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function raw(array $overrides = [], string $storageDir = '/nonexistent/var'): array
    {
        return TestConfig::merge([
            'auth' => [
                'mode' => 'imap_oauth',
                'allowed_ips' => ['203.0.113.0/24'],
                'oauth' => [
                    'resource' => self::RESOURCE,
                    'key' => 'base64:' . base64_encode(str_repeat('k', 32)),
                    'imap' => ['host' => 'imap.example.com'],
                    'allowed_email_domains' => ['example.com'],
                    'clients' => [
                        [
                            'id' => self::CLIENT,
                            'secret_hash' => TokenEntry::hashOf(self::CLIENT_SECRET),
                            'redirect_uris' => [self::REDIRECT],
                            'name' => 'Claude',
                        ],
                        [
                            'id' => self::PUBLIC_CLIENT,
                            'secret_hash' => null,
                            'redirect_uris' => [self::PUBLIC_REDIRECT],
                        ],
                    ],
                ],
            ],
            'runtime' => ['storage_dir' => $storageDir],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function config(array $overrides = [], string $storageDir = '/nonexistent/var'): Config
    {
        return ConfigParser::parse(self::raw($overrides, $storageDir), '/nonexistent');
    }

    /**
     * @return array{verifier: string, challenge: string}
     */
    public static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [
            'verifier' => $verifier,
            'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
    }
}

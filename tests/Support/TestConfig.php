<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use Cartwright\ImapMcp\Auth\OAuth\ClientSecret;
use Cartwright\ImapMcp\Auth\OAuth\Sealer;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Config\ConfigParser;

/**
 * A valid config.php for tests, and access tokens for it. Example values only (CLAUDE.md security rules).
 */
final class TestConfig
{
    public const RESOURCE = 'https://example.com/mcp/';
    public const ISSUER = 'https://example.com/mcp';
    /** Test-only key (32 bytes); never used anywhere real. */
    public const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';
    public const CLIENT = 'claude';
    public const CLIENT_SECRET = 'test-client-secret-0123456789abcdefghij';
    public const REDIRECT = 'https://client.example/api/mcp/auth_callback';
    public const PUBLIC_CLIENT = 'inspector';
    public const PUBLIC_REDIRECT = 'http://localhost:6274/oauth/callback';
    public const EMAIL = 'user@example.com';
    public const PASSWORD = 'right-password';
    public const GRANT = '0123456789abcdef0123456789abcdef';

    /**
     * @param array<string, mixed> $overrides merged recursively into the base config (null removes a key)
     *
     * @return array<string, mixed>
     */
    public static function raw(array $overrides = [], string $storageDir = '/nonexistent/var'): array
    {
        return self::merge([
            'auth' => [
                'allowed_ips' => ['203.0.113.0/24'],
                'oauth' => [
                    'resource' => self::RESOURCE,
                    'key' => 'base64:' . base64_encode(self::KEY),
                    'imap' => ['host' => 'imap.example.com'],
                    'allowed_email_domains' => ['example.com'],
                    'clients' => [
                        [
                            'id' => self::CLIENT,
                            'secret_hash' => ClientSecret::hash(self::CLIENT_SECRET),
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
     * An access token as the token endpoint would issue it for TestConfig.
     *
     * @param array<string, mixed> $claims overrides
     */
    public static function accessToken(array $claims = []): string
    {
        return (new Sealer(self::KEY))->seal(Sealer::ACCESS_TOKEN, $claims + [
            'jti' => bin2hex(random_bytes(16)),
            'gid' => self::GRANT,
            'sub' => self::EMAIL,
            'pwd' => self::PASSWORD,
            'cid' => self::CLIENT,
            'aud' => self::RESOURCE,
            'scope' => 'mail',
            'iat' => time(),
            'exp' => time() + 3600,
        ]);
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

    /**
     * Like array_replace_recursive, but lists (including []) replace instead of merging, and null removes a key.
     *
     * @param array<mixed> $base
     * @param array<mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } elseif (is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        /** @var array<string, mixed> $base */
        return $base;
    }
}

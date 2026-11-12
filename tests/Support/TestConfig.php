<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use Cartwright\ImapMcp\Auth\TokenEntry;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Config\ConfigParser;

/**
 * A valid config.php array for tests. Example values only (CLAUDE.md security rules).
 */
final class TestConfig
{
    /** Test-only token; not used anywhere real. */
    public const TOKEN = 'test-token-0123456789abcdefghijklmnopqrstuv';
    public const OTHER_TOKEN = 'test-token-rotated-0123456789abcdefghijklm';

    /**
     * @param array<string, mixed> $overrides merged recursively into the base config
     *
     * @return array<string, mixed>
     */
    public static function raw(array $overrides = [], string $storageDir = '/nonexistent/var'): array
    {
        $base = [
            'accounts' => [
                'default' => [
                    'email' => 'test@example.com',
                    'password' => 'test-password',
                    'imap' => ['host' => '127.0.0.1', 'port' => 3993, 'encryption' => 'ssl'],
                ],
            ],
            'auth' => [
                'tokens' => [
                    ['hash' => TokenEntry::hashOf(self::TOKEN), 'account' => 'default', 'label' => 'test'],
                ],
                'allowed_ips' => ['203.0.113.0/24'],
            ],
            'runtime' => ['storage_dir' => $storageDir],
        ];

        return self::merge($base, $overrides);
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

    /**
     * @param array<string, mixed> $overrides
     */
    public static function config(array $overrides = [], string $storageDir = '/nonexistent/var'): Config
    {
        return ConfigParser::parse(self::raw($overrides, $storageDir), '/nonexistent');
    }
}

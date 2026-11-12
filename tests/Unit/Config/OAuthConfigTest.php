<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Config;

use Cartwright\ImapMcp\Auth\OAuth\OAuthClient;
use Cartwright\ImapMcp\Auth\OAuth\OAuthSettings;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigParser;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigParser::class)]
#[CoversClass(Config::class)]
#[CoversClass(OAuthSettings::class)]
#[CoversClass(OAuthClient::class)]
final class OAuthConfigTest extends TestCase
{
    public function testDefaultsAndDerivedUrls(): void
    {
        $config = TestConfig::config();
        $oauth = $config->oauth;

        self::assertSame(3600, $oauth->accessTtlSec);
        self::assertSame(30, $oauth->refreshTtlDays);
        self::assertSame(30 * 86400, $oauth->refreshTtlSec());
        self::assertSame('https://example.com/mcp', $oauth->issuer());
        self::assertSame('https://example.com', $oauth->origin());
        self::assertSame(993, $oauth->imapPort);
        self::assertSame('ssl', $oauth->imapEncryption);
        self::assertTrue($oauth->clients['claude']->isConfidential());
        self::assertFalse($oauth->clients['inspector']->isConfidential());
        self::assertStringContainsString('public client', implode("\n", $config->warnings));
        self::assertStringNotContainsString(str_repeat('k', 32), print_r($oauth, true));
    }

    public function testRefreshDaysAreConfigurable(): void
    {
        $oauth = TestConfig::config(['auth' => ['oauth' => ['refresh_ttl_days' => 90]]])->oauth;

        self::assertSame(90 * 86400, $oauth->refreshTtlSec());
    }

    public function testResourceAndEmailMatching(): void
    {
        $oauth = TestConfig::config()->oauth;

        self::assertTrue($oauth->isOwnResource('https://example.com/mcp/'));
        self::assertTrue($oauth->isOwnResource('https://example.com/mcp'));
        self::assertTrue($oauth->isOwnResource('HTTPS://Example.COM/mcp/'));
        self::assertFalse($oauth->isOwnResource('https://example.com/other/'));
        self::assertFalse($oauth->isOwnResource('https://example.com.evil.example/mcp/'));
        self::assertFalse($oauth->isOwnResource('https://example.com/mcp/#x'));
        self::assertFalse($oauth->isOwnResource('not a url'));
        self::assertTrue($oauth->isEmailAllowed('User@Example.COM'));
        self::assertFalse($oauth->isEmailAllowed('user@sub.example.com'));
        self::assertFalse($oauth->isEmailAllowed('user@example.com.evil.example'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'http resource' => [
            ['auth' => ['oauth' => ['resource' => 'http://example.com/mcp/']]],
            'auth.oauth.resource',
        ];
        yield 'resource with query' => [
            ['auth' => ['oauth' => ['resource' => 'https://example.com/mcp/?a=1']]],
            'auth.oauth.resource',
        ];
        yield 'no key' => [['auth' => ['oauth' => ['key' => null]]], 'bin/generate-key.php'];
        yield 'short key' => [['auth' => ['oauth' => ['key' => 'base64:' . base64_encode('short')]]], 'auth.oauth.key'];
        yield 'no imap host' => [['auth' => ['oauth' => ['imap' => ['host' => null]]]], 'auth.oauth.imap.host'];
        yield 'plaintext imap' => [['auth' => ['oauth' => ['imap' => ['encryption' => 'none']]]], 'plaintext'];
        yield 'no domains' => [['auth' => ['oauth' => ['allowed_email_domains' => []]]], 'allowed_email_domains'];
        yield 'bad domain' => [
            ['auth' => ['oauth' => ['allowed_email_domains' => ['@example.com']]]],
            'allowed_email_domains[0]',
        ];
        yield 'access ttl too long' => [['auth' => ['oauth' => ['access_ttl_sec' => 86401]]], 'access_ttl_sec'];
        yield 'refresh days zero' => [
            ['auth' => ['oauth' => ['refresh_ttl_days' => 0]]],
            'refresh_ttl_days must be an integer from 1 to 365',
        ];
        yield 'no clients' => [
            ['auth' => ['oauth' => ['clients' => []]]],
            'auth.oauth.clients must be a non-empty list',
        ];
        yield 'http redirect' => [
            ['auth' => ['oauth' => ['clients' => [['id' => 'a', 'redirect_uris' => ['http://client.example/cb']]]]]],
            'redirect_uris[0]',
        ];
        yield 'redirect with fragment' => [
            ['auth' => ['oauth' => ['clients' => [['id' => 'a', 'redirect_uris' => ['https://client.example/cb#x']]]]]],
            'redirect_uris[0]',
        ];
        yield 'plain client secret' => [
            ['auth' => ['oauth' => ['clients' => [
                ['id' => 'a', 'secret_hash' => 'secret', 'redirect_uris' => ['https://c.example/cb']],
            ]]]],
            'secret_hash',
        ];
        yield 'duplicate client' => [
            ['auth' => ['oauth' => ['clients' => [
                ['id' => 'a', 'redirect_uris' => ['https://c.example/cb']],
                ['id' => 'a', 'redirect_uris' => ['https://c.example/cb']],
            ]]]],
            'duplicates',
        ];
        yield 'unknown oauth key' => [
            ['auth' => ['oauth' => ['allowed_domains' => ['example.com']]]],
            'unknown key auth.oauth.allowed_domains',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalid')]
    public function testInvalidOAuthConfigIsRefused(array $overrides, string $expected): void
    {
        try {
            TestConfig::config($overrides);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString($expected, implode("\n", $e->errors));
            self::assertStringNotContainsString(str_repeat('k', 32), $e->getMessage());
        }
    }

    public function testLocalhostIsAllowedForTestingWithAWarning(): void
    {
        $config = TestConfig::config(['auth' => ['oauth' => ['resource' => 'http://127.0.0.1:18080/']]]);

        self::assertStringContainsString('only for local testing', implode("\n", $config->warnings));
    }
}

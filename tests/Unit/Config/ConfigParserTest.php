<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Config;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\IpRange;
use Cartwright\ImapMcp\Auth\LockoutSettings;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigParser;
use Cartwright\ImapMcp\Config\RuntimeSettings;
use Cartwright\ImapMcp\Policy\MailPolicy;
use Cartwright\ImapMcp\Policy\ToolPolicy;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sections besides auth.oauth (that one is in OAuthConfigTest).
 */
#[CoversClass(ConfigParser::class)]
#[CoversClass(Config::class)]
#[CoversClass(ConfigException::class)]
#[CoversClass(Account::class)]
#[CoversClass(IpRange::class)]
#[CoversClass(LockoutSettings::class)]
#[CoversClass(RuntimeSettings::class)]
#[CoversClass(MailPolicy::class)]
#[CoversClass(ToolPolicy::class)]
final class ConfigParserTest extends TestCase
{
    public function testMinimalConfigGetsSecureDefaults(): void
    {
        $config = ConfigParser::parse(TestConfig::raw(['runtime' => null]), '/home/u/php-imap-mcp');

        self::assertSame([10, 600, 900], [
            $config->lockout->maxFailures,
            $config->lockout->windowSec,
            $config->lockout->banSec,
        ]);
        self::assertTrue($config->tools->isEnabled('search_emails'));
        self::assertTrue($config->tools->isEnabled('save_draft'));
        self::assertFalse($config->tools->isEnabled('set_flags'));
        self::assertFalse($config->tools->isEnabled('move_email'));
        self::assertSame(50, $config->policy->searchLimitMax);
        self::assertSame(20000, $config->policy->bodyMaxChars);
        self::assertTrue($config->policy->denyMoveToTrash);
        self::assertSame('Asia/Tokyo', $config->runtime->timezone->getName());
        self::assertSame('/home/u/php-imap-mcp/var', $config->runtime->storageDir);
        self::assertSame('/home/u/php-imap-mcp/var/logs', $config->runtime->auditLogDir());
        self::assertSame('/home/u/php-imap-mcp/var/oauth', $config->runtime->oauthDir());
        self::assertSame(
            ['auth.oauth.clients[1] has no secret_hash: it is a public client (PKCE only)'],
            $config->warnings,
        );
    }

    public function testFullExampleValues(): void
    {
        $config = TestConfig::config([
            'auth' => [
                'allowed_origins' => ['https://claude.ai'],
                'lockout' => ['max_failures' => 5, 'window_sec' => 60, 'ban_sec' => 120],
            ],
            'tools' => ['set_flags' => true, 'save_draft' => false],
            'policy' => ['allowed_folders' => ['INBOX'], 'search_limit_max' => 20, 'deny_move_to_trash' => false],
            'runtime' => ['timezone' => 'UTC', 'storage_dir' => 'data', 'log_level' => 'debug'],
        ]);

        self::assertSame(['https://claude.ai'], $config->allowedOrigins);
        self::assertSame(5, $config->lockout->maxFailures);
        self::assertTrue($config->tools->isEnabled('set_flags'));
        self::assertFalse($config->tools->isEnabled('save_draft'));
        self::assertTrue($config->policy->isFolderAllowed('INBOX'));
        self::assertFalse($config->policy->isFolderAllowed('Archive'));
        self::assertFalse($config->policy->denyMoveToTrash);
        self::assertSame('/nonexistent/data', $config->runtime->storageDir, 'relative to the config directory');
    }

    public function testIpRestriction(): void
    {
        $config = TestConfig::config(['auth' => ['allowed_ips' => ['203.0.113.0/24', '2001:db8::/32']]]);

        self::assertTrue($config->isIpAllowed('203.0.113.9'));
        self::assertTrue($config->isIpAllowed('2001:db8::9'));
        self::assertFalse($config->isIpAllowed('198.51.100.1'));
        self::assertFalse($config->isIpAllowed(''));
    }

    public function testEmptyAllowedIpsAllowsAllWithAWarning(): void
    {
        $config = TestConfig::config(['auth' => ['allowed_ips' => []]]);

        self::assertTrue($config->isIpAllowed('198.51.100.1'));
        self::assertStringContainsString('auth.allowed_ips is empty', implode("\n", $config->warnings));
    }

    public function testPasswordIsHiddenFromDumps(): void
    {
        $dump = print_r(TestConfig::config()->oauth->account(TestConfig::EMAIL, 'secret-password'), true);

        self::assertStringNotContainsString('secret-password', $dump);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'no oauth section' => [['auth' => ['oauth' => null]], 'auth.oauth.resource'];
        yield 'removed: accounts' => [['accounts' => ['default' => ['password' => 'x']]], 'accounts is no longer used'];
        yield 'removed: static tokens' => [['auth' => ['tokens' => []]], 'auth.tokens is no longer used'];
        yield 'removed: mode' => [['auth' => ['mode' => 'token']], 'auth.mode is no longer used'];
        yield 'bad ip' => [['auth' => ['allowed_ips' => ['203.0.113.0/40']]], 'auth.allowed_ips[0]'];
        yield 'ips not a list' => [['auth' => ['allowed_ips' => '203.0.113.0/24']], 'auth.allowed_ips must be'];
        yield 'origin with path' => [['auth' => ['allowed_origins' => ['https://claude.ai/']]], 'allowed_origins[0]'];
        yield 'lockout zero' => [['auth' => ['lockout' => ['max_failures' => 0]]], 'auth.lockout.max_failures'];
        yield 'tool flag not bool' => [['tools' => ['set_flags' => 1]], 'tools.set_flags must be true or false'];
        yield 'unknown tool' => [['tools' => ['send_email' => true]], 'unknown key tools.send_email'];
        yield 'search limit over 50' => [['policy' => ['search_limit_max' => 51]], 'policy.search_limit_max'];
        yield 'folders not list' => [['policy' => ['allowed_folders' => 'INBOX']], 'policy.allowed_folders'];
        yield 'trash flag not bool' => [['policy' => ['deny_move_to_trash' => 'yes']], 'deny_move_to_trash'];
        yield 'bad timezone' => [['runtime' => ['timezone' => 'Mars/Base']], 'runtime.timezone'];
        yield 'bad log level' => [['runtime' => ['log_level' => 'verbose']], 'runtime.log_level'];
        yield 'empty storage dir' => [['runtime' => ['storage_dir' => '']], 'runtime.storage_dir'];
        yield 'typo in security key' => [
            ['auth' => ['allowed_ip' => ['203.0.113.0/24']]],
            'unknown key auth.allowed_ip',
        ];
        yield 'unknown top-level key' => [['smtp' => ['host' => 'x']], 'unknown key smtp'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRefused(array $overrides, string $expectedError): void
    {
        try {
            TestConfig::config($overrides);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString($expectedError, implode("\n", $e->errors));
        }
    }

    public function testErrorsNeverContainSecretValues(): void
    {
        try {
            TestConfig::config([
                'accounts' => ['default' => ['password' => 'old-secret-password']],
                'auth' => ['oauth' => ['key' => 'secret-key-value', 'resource' => 'secret-resource-value']],
            ]);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('old-secret-password', $e->getMessage());
            self::assertStringNotContainsString('secret-key-value', $e->getMessage());
            self::assertStringNotContainsString('secret-resource-value', $e->getMessage());
        }
    }

    public function testAllErrorsAreReportedAtOnce(): void
    {
        try {
            TestConfig::config(['auth' => ['allowed_ips' => ['x']], 'policy' => ['body_max_chars' => 0]]);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertCount(2, $e->errors);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notAnArray(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [1];
        yield 'list' => [['a', 'b']];
    }

    #[DataProvider('notAnArray')]
    public function testConfigMustBeAnArray(mixed $raw): void
    {
        $this->expectException(ConfigException::class);

        ConfigParser::parse($raw, '/nonexistent');
    }
}

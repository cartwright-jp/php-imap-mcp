<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Config;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\IpRange;
use Cartwright\ImapMcp\Auth\LockoutSettings;
use Cartwright\ImapMcp\Auth\TokenEntry;
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

#[CoversClass(ConfigParser::class)]
#[CoversClass(Config::class)]
#[CoversClass(ConfigException::class)]
#[CoversClass(Account::class)]
#[CoversClass(IpRange::class)]
#[CoversClass(LockoutSettings::class)]
#[CoversClass(TokenEntry::class)]
#[CoversClass(RuntimeSettings::class)]
#[CoversClass(MailPolicy::class)]
#[CoversClass(ToolPolicy::class)]
final class ConfigParserTest extends TestCase
{
    public function testMinimalConfigGetsSecureDefaults(): void
    {
        $config = ConfigParser::parse(TestConfig::raw(['runtime' => null]), '/home/u/php-imap-mcp');

        $account = $config->accounts['default'];
        self::assertSame('test@example.com', $account->email);
        self::assertSame('test-password', $account->password());
        self::assertSame(3993, $account->imapPort);
        self::assertSame('ssl', $account->imapEncryption);
        self::assertNull($account->draftsFolder);
        self::assertSame('default', $config->tokens[0]->accountId);
        self::assertSame(hash('sha256', TestConfig::TOKEN), $config->tokens[0]->sha256);

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
        self::assertSame([], $config->warnings);
    }

    public function testFullExampleValues(): void
    {
        $config = TestConfig::config([
            'accounts' => ['default' => [
                'from_name' => '山田 太郎',
                'folders' => ['drafts' => 'Drafts', 'sent' => null],
                'imap' => ['encryption' => 'starttls', 'port' => 143],
            ]],
            'auth' => [
                'mode' => 'token',
                'allowed_origins' => ['https://claude.ai'],
                'lockout' => ['max_failures' => 5, 'window_sec' => 60, 'ban_sec' => 120],
            ],
            'tools' => ['set_flags' => true, 'save_draft' => false],
            'policy' => ['allowed_folders' => ['INBOX'], 'search_limit_max' => 20, 'deny_move_to_trash' => false],
            'runtime' => ['timezone' => 'UTC', 'storage_dir' => 'data', 'log_level' => 'debug'],
        ]);

        self::assertSame('山田 太郎', $config->accounts['default']->fromName);
        self::assertSame('Drafts', $config->accounts['default']->draftsFolder);
        self::assertSame('starttls', $config->accounts['default']->imapEncryption);
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
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('allowed_ips', $config->warnings[0]);
    }

    public function testPasswordIsHiddenFromDumps(): void
    {
        $dump = print_r(TestConfig::config()->accounts['default'], true);

        self::assertStringNotContainsString('test-password', $dump);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'no tokens' => [['auth' => ['tokens' => []]], 'auth.tokens must be a non-empty list'];
        yield 'tokens missing' => [['auth' => ['tokens' => null]], 'auth.tokens must be a non-empty list'];
        yield 'placeholder hash' => [
            ['auth' => ['tokens' => [['hash' => 'sha256:...', 'account' => 'default']]]],
            'auth.tokens[0].hash',
        ];
        yield 'plain token instead of hash' => [
            ['auth' => ['tokens' => [['hash' => TestConfig::TOKEN, 'account' => 'default']]]],
            'auth.tokens[0].hash',
        ];
        yield 'uppercase hash' => [
            ['auth' => ['tokens' => [['hash' => strtoupper(TokenEntry::hashOf('x')), 'account' => 'default']]]],
            'auth.tokens[0].hash',
        ];
        yield 'duplicate hash' => [
            ['auth' => ['tokens' => [
                ['hash' => TokenEntry::hashOf('x'), 'account' => 'default'],
                ['hash' => TokenEntry::hashOf('x'), 'account' => 'default'],
            ]]],
            'auth.tokens[1].hash duplicates',
        ];
        yield 'token for unknown account' => [
            ['auth' => ['tokens' => [['hash' => TokenEntry::hashOf('x'), 'account' => 'other']]]],
            'auth.tokens[0].account',
        ];
        yield 'no accounts' => [['accounts' => []], 'accounts must be a non-empty array'];
        yield 'bad email' => [['accounts' => ['default' => ['email' => 'not-an-email']]], 'accounts.default.email'];
        yield 'empty password' => [['accounts' => ['default' => ['password' => '']]], 'accounts.default.password'];
        yield 'no host' => [['accounts' => ['default' => ['imap' => ['host' => null]]]], 'accounts.default.imap.host'];
        yield 'bad port' => [['accounts' => ['default' => ['imap' => ['port' => 0]]]], 'accounts.default.imap.port'];
        yield 'port as string' => [
            ['accounts' => ['default' => ['imap' => ['port' => '993']]]],
            'accounts.default.imap.port',
        ];
        yield 'plaintext imap' => [
            ['accounts' => ['default' => ['imap' => ['encryption' => 'none']]]],
            'plaintext is not supported',
        ];
        yield 'bad account id' => [
            ['accounts' => ['bad id!' => TestConfig::raw()['accounts']['default']]],
            'account ids must be',
        ];
        yield 'oauth mode not yet' => [['auth' => ['mode' => 'imap_oauth']], 'auth.mode'];
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
        yield 'unknown account key' => [
            ['accounts' => ['default' => ['passwd' => 'x']]],
            'unknown key accounts.default.passwd',
        ];
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
                'accounts' => ['default' => ['email' => 'secret-pass-like-email', 'password' => ['nested']]],
                'auth' => ['tokens' => [['hash' => TestConfig::TOKEN, 'account' => 'default']]],
            ]);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString(TestConfig::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('secret-pass-like-email', $e->getMessage());
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

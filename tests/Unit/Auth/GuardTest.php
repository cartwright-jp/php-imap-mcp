<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Auth;

use Cartwright\ImapMcp\Auth\AuthenticationFailed;
use Cartwright\ImapMcp\Auth\Guard;
use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Auth\OAuth\AccessTokenAuthenticator;
use Cartwright\ImapMcp\Auth\OAuth\IdStore;
use Cartwright\ImapMcp\Auth\OAuth\Sealer;
use Cartwright\ImapMcp\Auth\Principal;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Tests\Support\MemoryAuditLogger;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Guard::class)]
#[CoversClass(Lockout::class)]
#[CoversClass(AccessTokenAuthenticator::class)]
#[CoversClass(AuthenticationFailed::class)]
final class GuardTest extends TestCase
{
    private const ALLOWED_IP = '203.0.113.10';

    private TempDirectory $dir;
    private MemoryAuditLogger $audit;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
        $this->audit = new MemoryAuditLogger();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testValidTokenFromAllowedIpPasses(): void
    {
        $result = $this->guard()->check($this->request('Bearer ' . TestConfig::accessToken()));

        self::assertInstanceOf(Principal::class, $result);
        self::assertSame(TestConfig::EMAIL, $result->account->email);
        self::assertSame(TestConfig::CLIENT, $result->clientId);
        self::assertSame(TestConfig::GRANT, $result->grantId);
        self::assertSame([], $this->audit->records->getArrayCopy());
    }

    public function testMissingTokenIs401WithChallengeAndIsLogged(): void
    {
        $response = $this->refused($this->guard()->check($this->request(null)));

        self::assertSame(401, $response->status);
        self::assertStringStartsWith('Bearer resource_metadata=', $response->headers['WWW-Authenticate']);
        self::assertStringNotContainsString('missing', $response->body, 'the reason is for the log only');
        self::assertSame([['event' => 'auth_failed', 'reason' => 'missing']], $this->audit->records->getArrayCopy());
    }

    public function testWrongTokenIs401AndTheTokenIsNotLogged(): void
    {
        $response = $this->refused($this->guard()->check($this->request('Bearer wrong-token-value')));

        self::assertSame(401, $response->status);
        self::assertSame('invalid', $this->audit->events('auth_failed')[0]['reason']);
        self::assertStringNotContainsString('wrong-token-value', serialize($this->audit->records->getArrayCopy()));
    }

    public function testDisallowedIpIs403EvenWithAValidToken(): void
    {
        $response = $this->refused(
            $this->guard()->check($this->request('Bearer ' . TestConfig::accessToken(), '198.51.100.1')),
        );

        self::assertSame(403, $response->status);
    }

    public function testUnknownRemoteAddressIsRefusedWhenIpsAreRestricted(): void
    {
        $result = $this->guard()->check($this->request('Bearer ' . TestConfig::accessToken(), ''));

        self::assertSame(403, $this->refused($result)->status);
    }

    public function testAnyIpIsAcceptedWhenNoRangeIsConfigured(): void
    {
        $guard = $this->guard(TestConfig::config(['auth' => ['allowed_ips' => []]]));

        $result = $guard->check($this->request('Bearer ' . TestConfig::accessToken(), '198.51.100.1'));

        self::assertInstanceOf(Principal::class, $result);
    }

    public function testForeignOriginIs403(): void
    {
        $token = 'Bearer ' . TestConfig::accessToken();
        $response = $this->refused($this->guard()->check(
            $this->request($token, self::ALLOWED_IP, ['Origin' => 'https://evil.example']),
        ));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Origin not allowed', $response->body);
    }

    public function testAllowedOriginPasses(): void
    {
        $guard = $this->guard(TestConfig::config(['auth' => ['allowed_origins' => ['https://app.example']]]));
        $token = 'Bearer ' . TestConfig::accessToken();

        self::assertInstanceOf(Principal::class, $guard->check(
            $this->request($token, self::ALLOWED_IP, ['Origin' => 'https://app.example']),
        ));
    }

    public function testFailedTokensAreLoggedButDoNotLockTheIpOut(): void
    {
        // Sealed tokens cannot be guessed, and every claude.ai user shares Anthropic's addresses: counting
        // failures here would only let anyone lock the owner out (M7 review, 2026-11-13).
        $guard = $this->guard(TestConfig::config(['auth' => ['lockout' => ['max_failures' => 2]]]));
        $expired = 'Bearer ' . TestConfig::accessToken(['exp' => time() - 1]);

        foreach (['Bearer wrong', null, 'Bearer wrong', null, $expired] as $authorization) {
            self::assertSame(401, $this->refused($guard->check($this->request($authorization)))->status);
        }

        self::assertInstanceOf(Principal::class, $guard->check($this->request('Bearer ' . TestConfig::accessToken())));
        self::assertCount(5, $this->audit->events('auth_failed'));
        self::assertSame([], $this->audit->events('lockout_started'));
        self::assertSame([], glob($this->dir->path . '/lockout/*'));
    }

    public function testAnIpBannedElsewhereIsRefusedWithoutLookingAtTheToken(): void
    {
        // The token endpoint still bans an IP after repeated client authentication failures.
        $config = TestConfig::config(['auth' => ['lockout' => ['max_failures' => 2]]]);
        $lockout = new Lockout($this->dir->path . '/lockout', $config->lockout);
        $lockout->recordFailure(self::ALLOWED_IP);
        $lockout->recordFailure(self::ALLOWED_IP);

        $token = 'Bearer ' . TestConfig::accessToken();
        $response = $this->refused($this->guard($config)->check($this->request($token)));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Too many authentication failures', $response->body);
        self::assertSame([], $this->audit->events('auth_failed'), 'refused before the token is looked at');
    }

    private function guard(?Config $config = null): Guard
    {
        $config ??= TestConfig::config();

        return new Guard(
            $config,
            new AccessTokenAuthenticator(
                $config->oauth,
                new Sealer($config->oauth->key),
                new IdStore($this->dir->path . '/oauth'),
            ),
            new Lockout($this->dir->path . '/lockout', $config->lockout),
            $this->audit,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(?string $authorization, string $ip = self::ALLOWED_IP, array $headers = []): Request
    {
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }

        return new Request('POST', $headers + ['Content-Type' => 'application/json'], '{}', false, $ip, '/mcp/');
    }

    private function refused(Response|Principal $result): Response
    {
        self::assertInstanceOf(Response::class, $result);

        return $result;
    }
}

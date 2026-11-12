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

    public function testRepeatedFailuresLockTheIpOutEvenForAValidToken(): void
    {
        $guard = $this->guard(TestConfig::config(['auth' => ['lockout' => ['max_failures' => 3]]]));

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(401, $this->refused($guard->check($this->request('Bearer wrong')))->status);
        }
        $response = $this->refused($guard->check($this->request('Bearer ' . TestConfig::accessToken())));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Too many authentication failures', $response->body);
        self::assertCount(3, $this->audit->events('auth_failed'), 'requests while banned are not logged one by one');
        self::assertSame([['event' => 'lockout_started', 'ban_sec' => 900]], $this->audit->events('lockout_started'));
    }

    public function testExpiredTokensDoNotCountTowardsTheLockout(): void
    {
        $guard = $this->guard(TestConfig::config(['auth' => ['lockout' => ['max_failures' => 2]]]));
        $expired = 'Bearer ' . TestConfig::accessToken(['exp' => time() - 1]);

        $guard->check($this->request($expired));
        $guard->check($this->request($expired));

        self::assertInstanceOf(Principal::class, $guard->check($this->request('Bearer ' . TestConfig::accessToken())));
    }

    public function testSuccessResetsTheFailureCount(): void
    {
        $guard = $this->guard(TestConfig::config(['auth' => ['lockout' => ['max_failures' => 2]]]));

        $guard->check($this->request('Bearer wrong'));
        $first = $guard->check($this->request('Bearer ' . TestConfig::accessToken()));
        $guard->check($this->request('Bearer wrong'));
        $second = $guard->check($this->request('Bearer ' . TestConfig::accessToken()));

        self::assertInstanceOf(Principal::class, $first);
        self::assertInstanceOf(Principal::class, $second, 'two failures in total, but never two in a row');
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

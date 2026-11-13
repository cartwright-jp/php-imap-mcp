<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Integration;

use Cartwright\ImapMcp\Application;
use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Auth\OAuth\AuthorizeEndpoint;
use Cartwright\ImapMcp\Auth\OAuth\CredentialVerifier;
use Cartwright\ImapMcp\Auth\OAuth\IdStore;
use Cartwright\ImapMcp\Auth\OAuth\TokenEndpoint;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Mail\Imap\ImapMailbox;
use Cartwright\ImapMcp\Tests\Support\MemoryAuditLogger;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OAuth end to end against the test mail server: the login is a real IMAP login, and the MCP tools then use the
 * credentials sealed in the access token (SPEC.md 7).
 */
#[CoversClass(Application::class)]
#[CoversClass(AuthorizeEndpoint::class)]
#[CoversClass(TokenEndpoint::class)]
#[CoversClass(CredentialVerifier::class)]
final class OAuthImapFlowTest extends TestCase
{
    private const EMAIL = 'test@example.com';
    private const PASSWORD = 'test-password';

    private TempDirectory $dir;
    private Config $config;

    protected function setUp(): void
    {
        $probe = new ImapMailbox($this->account(self::PASSWORD), 5, false);
        try {
            $probe->checkLogin();
        } catch (\Throwable) {
            $message = 'Test mail server is not reachable. Run: docker compose up -d';
            if (getenv('IMAP_TEST_REQUIRED') === '1') {
                self::fail($message);
            }
            self::markTestSkipped($message);
        } finally {
            $probe->close();
        }
        $this->dir = new TempDirectory();
        $this->config = TestConfig::config(['auth' => ['oauth' => [
            'imap' => ['host' => self::host(), 'port' => self::port()],
        ]]], $this->dir->path . '/var');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->dir->remove();
        }
    }

    public function testSignInWithTheMailPasswordAndUseTheTools(): void
    {
        $tokens = $this->signIn(self::PASSWORD);
        self::assertIsArray($tokens);

        $response = $this->app()->handle($this->toolCall((string) $tokens['access_token'], 'list_folders'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('"structuredContent":{"folders":[{"name":"INBOX"', $response->body);
        self::assertStringContainsString('"isError":false', $response->body);

        $refreshed = $this->app()->handle($this->post('/mcp/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $tokens['refresh_token'],
        ]));
        self::assertSame(200, $refreshed->status, 'refresh re-checks the IMAP login');
    }

    public function testWrongPasswordIsRejectedByTheMailServer(): void
    {
        $logged = ini_set('error_log', '/dev/null');
        try {
            self::assertNull($this->signIn('wrong-password'));
        } finally {
            ini_set('error_log', (string) $logged);
        }
    }

    /**
     * @return array<string, mixed>|null the token response, or null when the login failed
     */
    private function signIn(string $password): ?array
    {
        $pkce = TestConfig::pkce();
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => TestConfig::CLIENT,
            'redirect_uri' => TestConfig::REDIRECT,
            'state' => 's',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'resource' => TestConfig::RESOURCE,
        ]);
        $page = $this->app()->handle(new Request('GET', [], '', false, '198.51.100.20', '/mcp/authorize', $query));
        self::assertSame(200, $page->status);
        self::assertSame(1, preg_match('/name="request" value="([^"]+)"/', $page->body, $m));
        self::assertSame(1, preg_match('/pim_login=([^;]*)/', $page->headers['Set-Cookie'], $c));
        $login = $this->app()->handle(new Request(
            'POST',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => 'pim_login=' . $c[1]],
            http_build_query(['request' => html_entity_decode($m[1]), 'email' => self::EMAIL, 'password' => $password]),
            false,
            '198.51.100.20',
            '/mcp/authorize',
        ));
        if ($login->status !== 302) {
            self::assertStringContainsString('正しくありません', $login->body);
            return null;
        }
        parse_str((string) parse_url($login->headers['Location'], PHP_URL_QUERY), $params);
        $token = $this->app()->handle($this->post('/mcp/token', [
            'grant_type' => 'authorization_code',
            'code' => is_string($params['code'] ?? null) ? $params['code'] : '',
            'redirect_uri' => TestConfig::REDIRECT,
            'code_verifier' => $pkce['verifier'],
            'resource' => TestConfig::RESOURCE,
        ]));
        self::assertSame(200, $token->status, $token->body);
        $decoded = json_decode($token->body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function app(): Application
    {
        return new Application(
            $this->config,
            new MemoryAuditLogger(),
            new Lockout($this->dir->path . '/lockout', $this->config->lockout),
            // The test server's certificate is self-signed; production code always verifies (CLAUDE.md).
            static fn (Account $account): ImapMailbox => new ImapMailbox($account, 5, false),
            new IdStore($this->dir->path . '/oauth'),
        );
    }

    /**
     * @param array<string, string> $form
     */
    private function post(string $path, array $form): Request
    {
        return new Request('POST', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode(TestConfig::CLIENT . ':' . TestConfig::CLIENT_SECRET),
        ], http_build_query($form), false, '203.0.113.10', $path);
    }

    private function toolCall(string $accessToken, string $tool): Request
    {
        return new Request('POST', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => $tool,
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => new \stdClass(), '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], JSON_THROW_ON_ERROR), false, '203.0.113.10', '/mcp/');
    }

    private function account(string $password): Account
    {
        return new Account(self::EMAIL, self::EMAIL, $password, self::host(), self::port(), 'ssl');
    }

    private static function host(): string
    {
        $host = getenv('IMAP_TEST_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private static function port(): int
    {
        $port = getenv('IMAP_TEST_PORT');

        return is_string($port) && ctype_digit($port) ? (int) $port : 3993;
    }
}

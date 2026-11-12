<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Auth\OAuth;

use Cartwright\ImapMcp\Application;
use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Auth\OAuth\AccessTokenAuthenticator;
use Cartwright\ImapMcp\Auth\OAuth\AuthorizeEndpoint;
use Cartwright\ImapMcp\Auth\OAuth\Claims;
use Cartwright\ImapMcp\Auth\OAuth\CredentialVerifier;
use Cartwright\ImapMcp\Auth\OAuth\IdStore;
use Cartwright\ImapMcp\Auth\OAuth\LoginPage;
use Cartwright\ImapMcp\Auth\OAuth\Metadata;
use Cartwright\ImapMcp\Auth\OAuth\OAuthClient;
use Cartwright\ImapMcp\Auth\OAuth\OAuthSettings;
use Cartwright\ImapMcp\Auth\OAuth\Sealer;
use Cartwright\ImapMcp\Auth\OAuth\TokenEndpoint;
use Cartwright\ImapMcp\Auth\OAuth\TokenIssuer;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Tests\Support\FakeMailbox;
use Cartwright\ImapMcp\Tests\Support\MemoryAuditLogger;
use Cartwright\ImapMcp\Tests\Support\OAuthTestConfig;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The whole OAuth flow through Application, as a client and a browser would use it (SPEC.md 7; MCP
 * authorization; OAuth 2.1). See docs/oauth-checklist.md for the requirement each test covers.
 */
#[CoversClass(Application::class)]
#[CoversClass(AuthorizeEndpoint::class)]
#[CoversClass(TokenEndpoint::class)]
#[CoversClass(TokenIssuer::class)]
#[CoversClass(AccessTokenAuthenticator::class)]
#[CoversClass(CredentialVerifier::class)]
#[CoversClass(Metadata::class)]
#[CoversClass(LoginPage::class)]
#[CoversClass(OAuthSettings::class)]
#[CoversClass(OAuthClient::class)]
#[CoversClass(Claims::class)]
#[CoversClass(Sealer::class)]
#[CoversClass(IdStore::class)]
final class OAuthFlowTest extends TestCase
{
    private const SERVER_IP = '203.0.113.10';
    private const BROWSER_IP = '198.51.100.20';

    private TempDirectory $dir;
    private MemoryAuditLogger $audit;
    private string $password = OAuthTestConfig::PASSWORD;
    private bool $imapDown = false;
    /** @var list<Account> accounts the mailbox factory was called with */
    private array $accounts = [];

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
        $this->audit = new MemoryAuditLogger();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    // ---- the happy path ----

    public function testLoginTokenExchangeAndMcpCall(): void
    {
        $pkce = OAuthTestConfig::pkce();
        $page = $this->app()->handle($this->get('/mcp/authorize', $this->authorizeQuery($pkce)));

        self::assertSame(200, $page->status);
        self::assertStringContainsString('<strong>Claude</strong>', $page->body);
        self::assertStringContainsString('client.example', $page->body, 'the redirect host is shown');
        self::assertStringContainsString("frame-ancestors 'none'", $page->headers['Content-Security-Policy']);
        self::assertStringContainsString('https://client.example', $page->headers['Content-Security-Policy']);
        self::assertSame('DENY', $page->headers['X-Frame-Options']);
        self::assertStringContainsString('HttpOnly', $page->headers['Set-Cookie']);
        self::assertStringContainsString('Secure', $page->headers['Set-Cookie']);
        self::assertStringContainsString('Path=/mcp/;', $page->headers['Set-Cookie'], 'not sent to the whole site');

        $redirect = $this->submitLogin($page);
        self::assertSame(302, $redirect->status);
        $location = $redirect->headers['Location'];
        self::assertStringStartsWith(OAuthTestConfig::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame('xyz', $params['state']);
        self::assertSame(OAuthTestConfig::ISSUER, $params['iss'], 'RFC 9207');
        self::assertIsString($params['code']);
        self::assertStringStartsWith('pim_ac_', $params['code']);
        self::assertStringNotContainsString(OAuthTestConfig::PASSWORD, $location);
        self::assertStringContainsString('Max-Age=0', $redirect->headers['Set-Cookie'], 'the login cookie is cleared');

        $tokens = $this->exchange($params['code'], $pkce['verifier']);
        self::assertSame(200, $tokens['status']);
        self::assertSame('Bearer', $tokens['body']['token_type']);
        self::assertSame(3600, $tokens['body']['expires_in']);
        self::assertSame('mail', $tokens['body']['scope']);
        self::assertStringStartsWith('pim_at_', $tokens['body']['access_token']);
        self::assertStringStartsWith('pim_rt_', $tokens['body']['refresh_token']);

        $this->accounts = [];
        $list = $this->app()->handle($this->mcp($tokens['body']['access_token']));
        self::assertSame(200, $list->status, $list->body);
        self::assertStringContainsString('"name":"search_emails"', $list->body);
        $account = $this->firstAccount();
        self::assertSame(OAuthTestConfig::EMAIL, $account->email);
        self::assertSame(OAuthTestConfig::PASSWORD, $account->password());
        self::assertSame('imap.example.com', $account->imapHost);

        self::assertCount(1, $this->audit->events('oauth_login'));
        self::assertCount(1, $this->audit->events('oauth_token'));
        self::assertStringNotContainsString(
            OAuthTestConfig::PASSWORD,
            serialize($this->audit->records->getArrayCopy()),
            'passwords never reach the audit log',
        );
    }

    public function testPublicClientUsesPkceWithoutSecret(): void
    {
        $pkce = OAuthTestConfig::pkce();
        $query = ['client_id' => OAuthTestConfig::PUBLIC_CLIENT, 'redirect_uri' => OAuthTestConfig::PUBLIC_REDIRECT];
        $code = $this->loginForCode($pkce, $query);

        $tokens = $this->exchange($code, $pkce['verifier'], [
            'client_id' => OAuthTestConfig::PUBLIC_CLIENT,
            'redirect_uri' => OAuthTestConfig::PUBLIC_REDIRECT,
        ], null);

        self::assertSame(200, $tokens['status'], json_encode($tokens['body'], JSON_THROW_ON_ERROR));
    }

    // ---- refresh ----

    public function testRefreshRotatesTokens(): void
    {
        $first = $this->tokens();

        $second = $this->refresh($first['refresh_token']);

        self::assertSame(200, $second['status']);
        self::assertNotSame($first['refresh_token'], $second['body']['refresh_token']);
        self::assertSame(200, $this->app()->handle($this->mcp($second['body']['access_token']))->status);
        // The new refresh token works again (the grant is extended on every use).
        self::assertSame(200, $this->refresh($second['body']['refresh_token'])['status']);
    }

    public function testReusedRefreshTokenRevokesTheWholeGrant(): void
    {
        $first = $this->tokens();
        $second = $this->refresh($first['refresh_token']);

        $reuse = $this->refresh($first['refresh_token']);

        self::assertSame(400, $reuse['status']);
        self::assertSame('invalid_grant', $reuse['body']['error']);
        self::assertCount(1, $this->audit->events('oauth_refresh_reuse'));
        self::assertSame('invalid_grant', $this->refresh($second['body']['refresh_token'])['body']['error']);
        $mcp = $this->app()->handle($this->mcp($second['body']['access_token']));
        self::assertSame(401, $mcp->status, 'access tokens of the revoked grant stop working');
        self::assertSame('revoked', $this->audit->events('auth_failed')[0]['reason']);
    }

    public function testChangedMailPasswordEndsTheGrantAtRefresh(): void
    {
        $first = $this->tokens();
        $this->password = 'changed-on-the-mail-server';

        $refresh = $this->refresh($first['refresh_token']);

        self::assertSame('invalid_grant', $refresh['body']['error']);
        self::assertSame(401, $this->app()->handle($this->mcp($first['access_token']))->status);
    }

    public function testUnreachableMailServerDoesNotCostTheRefreshToken(): void
    {
        $first = $this->tokens();
        $this->imapDown = true;

        $down = $this->refresh($first['refresh_token']);
        $this->imapDown = false;
        $retry = $this->refresh($first['refresh_token']);

        self::assertSame(503, $down['status']);
        self::assertSame('temporarily_unavailable', $down['body']['error']);
        self::assertSame(200, $retry['status']);
    }

    public function testRefreshForAnotherResourceOrScopeIsRefused(): void
    {
        $first = $this->tokens();

        $otherResource = $this->refresh($first['refresh_token'], ['resource' => 'https://other.example/mcp/']);
        self::assertSame('invalid_target', $otherResource['body']['error']);
        $otherScope = $this->refresh($first['refresh_token'], ['scope' => 'admin']);
        self::assertSame('invalid_scope', $otherScope['body']['error']);
        $upperCase = $this->refresh($first['refresh_token'], ['resource' => 'HTTPS://EXAMPLE.COM/mcp']);
        self::assertSame(200, $upperCase['status']);
    }

    // ---- authorization code ----

    public function testCodeWorksOnce(): void
    {
        $pkce = OAuthTestConfig::pkce();
        $code = $this->loginForCode($pkce);

        self::assertSame(200, $this->exchange($code, $pkce['verifier'])['status']);
        self::assertSame('invalid_grant', $this->exchange($code, $pkce['verifier'])['body']['error']);
    }

    public function testWrongVerifierIsRejectedWithoutBurningTheCode(): void
    {
        $pkce = OAuthTestConfig::pkce();
        $code = $this->loginForCode($pkce);

        self::assertSame('invalid_grant', $this->exchange($code, OAuthTestConfig::pkce()['verifier'])['body']['error']);
        self::assertSame('invalid_request', $this->exchange($code, 'short')['body']['error']);
        self::assertSame(200, $this->exchange($code, $pkce['verifier'])['status']);
    }

    public function testCodeIsBoundToRedirectUriClientAndResource(): void
    {
        $pkce = OAuthTestConfig::pkce();
        $code = $this->loginForCode($pkce);

        self::assertSame(
            'invalid_grant',
            $this->exchange($code, $pkce['verifier'], ['redirect_uri' => 'https://client.example/other'])
                ['body']['error'],
        );
        self::assertSame('invalid_grant', $this->exchange($code, $pkce['verifier'], [
            'client_id' => OAuthTestConfig::PUBLIC_CLIENT,
            'redirect_uri' => OAuthTestConfig::REDIRECT,
        ], null)['body']['error'], 'another client cannot redeem it');
        self::assertSame(
            'invalid_target',
            $this->exchange($code, $pkce['verifier'], ['resource' => 'https://other.example/mcp/'])['body']['error'],
        );
    }

    // ---- client authentication ----

    public function testConfidentialClientMustAuthenticate(): void
    {
        $pkce = OAuthTestConfig::pkce();
        $code = $this->loginForCode($pkce);

        $wrong = $this->exchange($code, $pkce['verifier'], [], 'wrong-secret');
        $none = $this->exchange($code, $pkce['verifier'], ['client_id' => OAuthTestConfig::CLIENT], null);
        $post = $this->exchange($code, $pkce['verifier'], [
            'client_id' => OAuthTestConfig::CLIENT,
            'client_secret' => OAuthTestConfig::CLIENT_SECRET,
        ], null);

        self::assertSame(401, $wrong['status']);
        self::assertSame('invalid_client', $wrong['body']['error']);
        self::assertSame('Basic realm="php-imap-mcp"', $wrong['headers']['WWW-Authenticate']);
        self::assertSame(401, $none['status']);
        self::assertSame(200, $post['status'], 'client_secret_post works too');
    }

    // ---- the authorization request ----

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unsafeToRedirect(): iterable
    {
        yield 'unknown client' => [['client_id' => 'evil']];
        yield 'unregistered redirect URI' => [['redirect_uri' => 'https://evil.example/callback']];
        yield 'redirect URI with extra path' => [['redirect_uri' => OAuthTestConfig::REDIRECT . '/x']];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('unsafeToRedirect')]
    public function testErrorsBeforeTheRedirectUriIsTrustedAreShownNotRedirected(array $override): void
    {
        $query = $override + $this->authorizeQuery(OAuthTestConfig::pkce());
        $response = $this->app()->handle($this->get('/mcp/authorize', $query));

        self::assertSame(400, $response->status);
        self::assertArrayNotHasKey('Location', $response->headers);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function badRequests(): iterable
    {
        yield 'no PKCE' => [['code_challenge' => null, 'code_challenge_method' => null], 'invalid_request'];
        yield 'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'];
        yield 'implicit flow' => [['response_type' => 'token'], 'unsupported_response_type'];
        yield 'another resource' => [['resource' => 'https://other.example/mcp/'], 'invalid_target'];
        yield 'unknown scope' => [['scope' => 'mail admin'], 'invalid_scope'];
    }

    /**
     * @param array<string, string|null> $override
     */
    #[DataProvider('badRequests')]
    public function testInvalidRequestsAreRedirectedWithAnError(array $override, string $error): void
    {
        $query = array_filter(
            $override + $this->authorizeQuery(OAuthTestConfig::pkce()),
            static fn (?string $v): bool => $v !== null,
        );

        $response = $this->app()->handle($this->get('/mcp/authorize', $query));

        self::assertSame(302, $response->status);
        parse_str((string) parse_url($response->headers['Location'], PHP_URL_QUERY), $params);
        self::assertSame($error, $params['error']);
        self::assertSame('xyz', $params['state']);
        self::assertSame(OAuthTestConfig::ISSUER, $params['iss']);
    }

    public function testRepeatedParametersAreRejected(): void
    {
        $query = http_build_query($this->authorizeQuery(OAuthTestConfig::pkce())) . '&client_id=claude';

        self::assertSame(400, $this->app()->handle($this->get('/mcp/authorize', [], $query))->status);
    }

    // ---- the login form ----

    public function testWrongPasswordShowsTheFormAgainAndRepeatedFailuresLockOut(): void
    {
        $config = OAuthTestConfig::config(['auth' => ['lockout' => ['max_failures' => 3]]], $this->dir->path . '/var');
        $page = $this->app($config)->handle(
            $this->get('/mcp/authorize', $this->authorizeQuery(OAuthTestConfig::pkce())),
        );

        $failed = $this->submitLogin($page, 'wrong-password', $config);
        self::assertSame(400, $failed->status);
        self::assertStringContainsString('メールアドレスまたはパスワードが正しくありません', $failed->body);
        self::assertStringContainsString(
            'value="' . OAuthTestConfig::EMAIL . '"',
            $failed->body,
            'the address is kept',
        );
        $this->submitLogin($page, 'wrong-password', $config);
        $this->submitLogin($page, 'wrong-password', $config);

        $locked = $this->submitLogin($page, OAuthTestConfig::PASSWORD, $config);
        self::assertSame(429, $locked->status, 'even the right password waits');
        self::assertCount(3, $this->audit->events('oauth_login_failed'));
    }

    public function testOnlyAllowedMailDomainsMaySignIn(): void
    {
        $page = $this->app()->handle($this->get('/mcp/authorize', $this->authorizeQuery(OAuthTestConfig::pkce())));
        $this->accounts = [];

        $response = $this->submitLogin($page, OAuthTestConfig::PASSWORD, null, 'someone@other.example');

        self::assertSame(400, $response->status);
        self::assertStringContainsString('このメールアドレスではログインできません', $response->body);
        self::assertSame([], $this->accounts, 'the IMAP server is not even asked');
    }

    public function testLoginWithoutTheCookieOrFromAnotherOriginIsRefused(): void
    {
        $page = $this->app()->handle($this->get('/mcp/authorize', $this->authorizeQuery(OAuthTestConfig::pkce())));

        self::assertSame(403, $this->submitLogin($page, cookie: false)->status);
        self::assertSame(403, $this->submitLogin($page, origin: 'https://evil.example')->status);
        self::assertSame(302, $this->submitLogin($page, origin: 'https://example.com')->status);
    }

    public function testTamperedLoginRequestIsRefused(): void
    {
        $response = $this->app()->handle($this->post('/mcp/authorize', [
            'request' => 'pim_rq_forged',
            'email' => OAuthTestConfig::EMAIL,
            'password' => OAuthTestConfig::PASSWORD,
        ], ['Cookie' => 'pim_login=x'], self::BROWSER_IP));

        self::assertSame(400, $response->status);
    }

    // ---- the resource server ----

    public function testMcpWithoutTokenPointsToTheMetadata(): void
    {
        $missing = $this->app()->handle($this->mcp(null));
        $garbage = $this->app()->handle($this->mcp('pim_at_garbage'));

        self::assertSame(401, $missing->status);
        self::assertSame(
            'Bearer resource_metadata="https://example.com/mcp/.well-known/oauth-protected-resource", scope="mail"',
            $missing->headers['WWW-Authenticate'],
        );
        self::assertStringEndsWith(', error="invalid_token"', $garbage->headers['WWW-Authenticate']);
    }

    public function testTokensForOtherResourcesOrOfOtherTypesAreRejected(): void
    {
        $sealer = new Sealer(str_repeat('k', 32));
        $claims = [
            'jti' => 'j', 'gid' => 'g', 'sub' => OAuthTestConfig::EMAIL, 'pwd' => 'p', 'cid' => OAuthTestConfig::CLIENT,
            'aud' => 'https://other.example/mcp/', 'scope' => 'mail', 'exp' => time() + 60,
        ];

        $otherAudience = $this->app()->handle($this->mcp($sealer->seal(Sealer::ACCESS_TOKEN, $claims)));
        $expired = $this->app()->handle($this->mcp($sealer->seal(
            Sealer::ACCESS_TOKEN,
            ['aud' => OAuthTestConfig::RESOURCE, 'exp' => time() - 1] + $claims,
        )));
        $refreshAsAccess = $this->app()->handle($this->mcp($this->tokens()['refresh_token']));

        self::assertSame(401, $otherAudience->status);
        self::assertSame(401, $expired->status);
        self::assertSame(401, $refreshAsAccess->status);
        self::assertSame(
            ['audience', 'expired', 'invalid'],
            array_column($this->audit->events('auth_failed'), 'reason'),
        );
    }

    public function testExpiredCodesRefreshTokensAndLoginFormsAreRefused(): void
    {
        $sealer = new Sealer(str_repeat('k', 32));
        $past = time() - 1;
        $pkce = OAuthTestConfig::pkce();
        $base = [
            'jti' => 'j', 'gid' => 'g', 'sub' => OAuthTestConfig::EMAIL, 'pwd' => OAuthTestConfig::PASSWORD,
            'cid' => OAuthTestConfig::CLIENT, 'aud' => OAuthTestConfig::RESOURCE, 'scope' => 'mail', 'exp' => $past,
        ];
        $code = $sealer->seal(Sealer::AUTHORIZATION_CODE, $base + [
            'ru' => OAuthTestConfig::REDIRECT,
            'cc' => $pkce['challenge'],
        ]);
        $refresh = $sealer->seal(Sealer::REFRESH_TOKEN, $base);
        $form = $sealer->seal(Sealer::LOGIN_REQUEST, [
            'cid' => OAuthTestConfig::CLIENT, 'ru' => OAuthTestConfig::REDIRECT, 'cc' => $pkce['challenge'],
            'csrf' => 'c', 'exp' => $past,
        ]);

        self::assertSame('invalid_grant', $this->exchange($code, $pkce['verifier'])['body']['error']);
        self::assertSame('invalid_grant', $this->refresh($refresh)['body']['error']);
        $login = $this->app()->handle($this->post('/mcp/authorize', [
            'request' => $form,
            'email' => OAuthTestConfig::EMAIL,
            'password' => OAuthTestConfig::PASSWORD,
        ], ['Cookie' => 'pim_login=c'], self::BROWSER_IP));
        self::assertSame(400, $login->status);
        self::assertStringContainsString('有効期限が切れました', $login->body);
    }

    public function testRemovedClientLosesAccessAtOnce(): void
    {
        $tokens = $this->tokens();
        $config = OAuthTestConfig::config(['auth' => ['oauth' => ['clients' => [[
            'id' => 'someone-else',
            'secret_hash' => null,
            'redirect_uris' => ['https://client.example/cb'],
        ]]]]], $this->dir->path . '/var');

        self::assertSame(401, $this->app($config)->handle($this->mcp($tokens['access_token']))->status);
    }

    // ---- metadata and IP restriction ----

    public function testDiscoveryDocuments(): void
    {
        $resource = $this->json(
            $this->app()->handle($this->get('/mcp/.well-known/oauth-protected-resource', [], '', self::SERVER_IP)),
        );
        self::assertSame(OAuthTestConfig::RESOURCE, $resource['resource']);
        self::assertSame([OAuthTestConfig::ISSUER], $resource['authorization_servers']);
        self::assertSame(['mail'], $resource['scopes_supported']);

        foreach (['/mcp/.well-known/openid-configuration', '/.well-known/oauth-authorization-server/mcp'] as $path) {
            $server = $this->json($this->app()->handle($this->get($path, [], '', self::SERVER_IP)));
            self::assertSame(OAuthTestConfig::ISSUER, $server['issuer'], $path);
            self::assertSame('https://example.com/mcp/authorize', $server['authorization_endpoint']);
            self::assertSame('https://example.com/mcp/token', $server['token_endpoint']);
            self::assertSame(['S256'], $server['code_challenge_methods_supported']);
            self::assertTrue($server['authorization_response_iss_parameter_supported']);
            self::assertArrayNotHasKey('registration_endpoint', $server);
        }
    }

    public function testOnlyTheLoginPageIsOpenToEveryIp(): void
    {
        $app = $this->app();

        $login = $app->handle($this->get('/mcp/authorize', $this->authorizeQuery(OAuthTestConfig::pkce())));
        self::assertSame(200, $login->status);
        self::assertSame(403, $app->handle($this->get('/mcp/.well-known/oauth-protected-resource', []))->status);
        $token = $app->handle($this->post('/mcp/token', ['grant_type' => 'refresh_token'], [], self::BROWSER_IP));
        self::assertSame(403, $token->status);
        self::assertSame(403, $app->handle($this->mcp('x', self::BROWSER_IP))->status);
    }

    public function testRouting(): void
    {
        self::assertSame(Application::ROUTE_AUTHORIZE, Application::route('/mcp/authorize'));
        self::assertSame(Application::ROUTE_TOKEN, Application::route('/token'));
        self::assertSame(
            Application::ROUTE_RESOURCE_METADATA,
            Application::route('/.well-known/oauth-protected-resource/mcp'),
        );
        self::assertSame(
            Application::ROUTE_SERVER_METADATA,
            Application::route('/mcp/.well-known/openid-configuration'),
        );
        self::assertSame(Application::ROUTE_MCP, Application::route('/mcp/'));
        self::assertSame(Application::ROUTE_MCP, Application::route('/mcp/index.php'));
    }

    // ---- helpers ----

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private function tokens(): array
    {
        $pkce = OAuthTestConfig::pkce();
        $tokens = $this->exchange($this->loginForCode($pkce), $pkce['verifier']);
        self::assertSame(200, $tokens['status']);

        return [
            'access_token' => (string) $tokens['body']['access_token'],
            'refresh_token' => (string) $tokens['body']['refresh_token'],
        ];
    }

    /**
     * @param array{verifier: string, challenge: string} $pkce
     * @param array<string, string> $query
     */
    private function loginForCode(array $pkce, array $query = []): string
    {
        $page = $this->app()->handle($this->get('/mcp/authorize', $query + $this->authorizeQuery($pkce)));
        self::assertSame(200, $page->status, $page->body);
        $redirect = $this->submitLogin($page);
        self::assertSame(302, $redirect->status, $redirect->body);
        parse_str((string) parse_url($redirect->headers['Location'], PHP_URL_QUERY), $params);
        self::assertIsString($params['code']);

        return $params['code'];
    }

    private function submitLogin(
        Response $page,
        string $password = OAuthTestConfig::PASSWORD,
        ?Config $config = null,
        string $email = OAuthTestConfig::EMAIL,
        bool $cookie = true,
        ?string $origin = null,
    ): Response {
        preg_match('/name="request" value="([^"]+)"/', $page->body, $m);
        preg_match('/pim_login=([^;]*)/', $page->headers['Set-Cookie'] ?? '', $c);
        $headers = $cookie ? ['Cookie' => 'other=1; pim_login=' . ($c[1] ?? '')] : [];
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        return $this->app($config)->handle($this->post('/mcp/authorize', [
            'request' => html_entity_decode($m[1] ?? ''),
            'email' => $email,
            'password' => $password,
        ], $headers, self::BROWSER_IP));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function exchange(
        string $code,
        string $verifier,
        array $extra = [],
        ?string $secret = OAuthTestConfig::CLIENT_SECRET,
    ): array {
        $headers = $secret === null
            ? []
            : ['Authorization' => 'Basic ' . base64_encode(OAuthTestConfig::CLIENT . ':' . $secret)];
        $response = $this->app()->handle($this->post('/mcp/token', $extra + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => OAuthTestConfig::REDIRECT,
            'code_verifier' => $verifier,
            'resource' => OAuthTestConfig::RESOURCE,
        ], $headers));

        return ['status' => $response->status, 'body' => $this->json($response), 'headers' => $response->headers];
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function refresh(string $refreshToken, array $extra = []): array
    {
        $response = $this->app()->handle($this->post('/mcp/token', $extra + [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], ['Authorization' => self::basic()]));

        return ['status' => $response->status, 'body' => $this->json($response)];
    }

    /**
     * @param array{verifier: string, challenge: string} $pkce
     *
     * @return array<string, string>
     */
    private function authorizeQuery(array $pkce): array
    {
        return [
            'response_type' => 'code',
            'client_id' => OAuthTestConfig::CLIENT,
            'redirect_uri' => OAuthTestConfig::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'resource' => OAuthTestConfig::RESOURCE,
            'scope' => 'mail',
        ];
    }

    private function firstAccount(): Account
    {
        self::assertArrayHasKey(0, $this->accounts, 'the mailbox factory was called');

        return $this->accounts[0];
    }

    private static function basic(): string
    {
        return 'Basic ' . base64_encode(OAuthTestConfig::CLIENT . ':' . OAuthTestConfig::CLIENT_SECRET);
    }

    private function app(?Config $config = null): Application
    {
        $config ??= OAuthTestConfig::config([], $this->dir->path . '/var');

        return new Application(
            $config,
            $this->audit,
            new Lockout($this->dir->path . '/lockout', $config->lockout),
            function (Account $account): FakeMailbox {
                $this->accounts[] = $account;
                $mailbox = new FakeMailbox();
                if ($this->imapDown) {
                    $mailbox->failWith = MailException::unavailable();
                } elseif ($account->password() !== $this->password) {
                    $mailbox->failWith = MailException::loginFailed();
                }

                return $mailbox;
            },
            new IdStore($this->dir->path . '/oauth'),
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function get(
        string $path,
        array $query,
        string $rawQuery = '',
        string $ip = self::BROWSER_IP,
        array $headers = [],
    ): Request {
        $queryString = $rawQuery !== '' ? $rawQuery : http_build_query($query);

        return new Request('GET', $headers, '', false, $ip, $path, $queryString);
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     */
    private function post(string $path, array $form, array $headers = [], string $ip = self::SERVER_IP): Request
    {
        return new Request(
            'POST',
            $headers + ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
            false,
            $ip,
            $path,
        );
    }

    private function mcp(?string $token, string $ip = self::SERVER_IP): Request
    {
        $headers = [
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], JSON_THROW_ON_ERROR);

        return new Request('POST', $headers, $body, false, $ip, '/mcp/');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, $response->body);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

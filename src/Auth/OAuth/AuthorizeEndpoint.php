<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Log\AuditLogger;
use Cartwright\ImapMcp\Mail\MailException;
use Closure;

/**
 * The authorization endpoint and login page (OAuth 2.1 4.1; SPEC.md 7).
 *
 * It is opened by the user's browser from anywhere, so it is not IP-restricted. Instead: exact redirect URI
 * match before any redirect, PKCE S256 only, CSRF protection (a cookie bound to the sealed login request),
 * an Origin check, per-IP and per-address attempt limits, an allow list of mail domains, and no framing.
 * The identity check is an IMAP login; nothing is stored, the credentials travel sealed in the code.
 */
final class AuthorizeEndpoint
{
    public const CSRF_COOKIE = 'pim_login';
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9\-_]{43}$/';
    private const STATE_MAX = 1024;
    private const TOO_MANY = '試行回数が多すぎます。しばらくしてからやり直してください。';
    private const UNREACHABLE = 'メールサーバーに接続できません。しばらくしてからやり直してください。';
    private const PARAMETERS = [
        'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method',
        'resource', 'scope',
    ];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly OAuthSettings $settings,
        private readonly Sealer $sealer,
        private readonly CredentialVerifier $verifier,
        private readonly Lockout $lockout,
        private readonly AuditLogger $audit,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function handle(Request $request): Response
    {
        return match ($request->method) {
            'GET' => $this->show($request),
            'POST' => $this->login($request),
            default => Response::empty(405, ['Allow' => 'GET, POST']),
        };
    }

    /**
     * GET: validates the authorization request and shows the login form.
     */
    private function show(Request $request): Response
    {
        foreach (self::PARAMETERS as $name) {
            if ($request->isRepeated($name)) {
                return LoginPage::error('リクエストが正しくありません（パラメータが重複しています）。');
            }
        }
        // Until client and redirect URI are verified, errors are shown here and never redirected
        // (OAuth 2.1 4.1.2.1: no open redirector).
        $client = $this->settings->clients[(string) $request->query('client_id')] ?? null;
        if ($client === null) {
            return LoginPage::error('登録されていないクライアントです。');
        }
        $redirectUri = (string) $request->query('redirect_uri');
        if (!$client->allowsRedirectUri($redirectUri)) {
            return LoginPage::error('リダイレクト先が登録されたものと一致しません。');
        }
        $state = $request->query('state');
        $fail = fn (string $error, string $description): Response
            => $this->redirect($redirectUri, ['error' => $error, 'error_description' => $description], $state);

        if ($state !== null && strlen($state) > self::STATE_MAX) {
            return $fail('invalid_request', 'state is too long');
        }
        if ($request->query('response_type') !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported');
        }
        $challenge = (string) $request->query('code_challenge');
        $method = $request->query('code_challenge_method');
        if ($method !== 'S256' || preg_match(self::CHALLENGE_PATTERN, $challenge) !== 1) {
            return $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required');
        }
        $resource = $request->query('resource');
        if ($resource !== null && !$this->settings->isOwnResource($resource)) {
            return $fail('invalid_target', 'The resource is not served by this authorization server');
        }
        $scope = $request->query('scope');
        foreach (explode(' ', trim((string) $scope)) as $requested) {
            if ($requested !== '' && $requested !== OAuthSettings::SCOPE) {
                return $fail('invalid_scope', sprintf("Only the scope '%s' exists", OAuthSettings::SCOPE));
            }
        }

        $csrf = bin2hex(random_bytes(16));
        $sealed = $this->sealer->seal(Sealer::LOGIN_REQUEST, [
            'cid' => $client->id,
            'ru' => $redirectUri,
            'st' => $state,
            'cc' => $challenge,
            'csrf' => $csrf,
            'exp' => ($this->clock)() + OAuthSettings::LOGIN_FORM_TTL_SEC,
        ]);
        $response = $this->form($client, $redirectUri, $sealed);

        return $this->withCookie($response, $csrf, OAuthSettings::LOGIN_FORM_TTL_SEC);
    }

    /**
     * POST: checks the credentials with an IMAP login and redirects back with a code.
     */
    private function login(Request $request): Response
    {
        $claims = $this->sealer->open(Sealer::LOGIN_REQUEST, (string) $request->form('request'));
        $login = Claims::of($claims, ['cid', 'ru', 'cc', 'csrf']);
        if ($login === null || $login->int('exp') <= ($this->clock)()) {
            return LoginPage::error('ログインの有効期限が切れました。');
        }
        $client = $this->settings->clients[$login->string('cid')] ?? null;
        if ($client === null || !$client->allowsRedirectUri($login->string('ru'))) {
            return LoginPage::error('登録されていないクライアントです。');
        }
        // CSRF: the form must come from this server's page in this browser (login CSRF would sign the victim
        // in to the attacker's mailbox).
        $origin = $request->header('Origin');
        $cookie = $request->cookie(self::CSRF_COOKIE);
        if (
            ($origin !== null && $origin !== 'null' && $origin !== $this->settings->origin())
            || $cookie === null || !hash_equals($login->string('csrf'), $cookie)
        ) {
            return LoginPage::error('ブラウザの確認に失敗しました（Cookie を有効にしてください）。', 403);
        }
        $state = is_string($claims['st'] ?? null) ? $claims['st'] : null;
        $sealedRequest = (string) $request->form('request');

        $email = trim((string) $request->form('email'));
        $password = (string) $request->form('password');
        $ipKey = 'login-ip:' . $request->remoteAddr;
        $mailKey = 'login-mail:' . strtolower($email);
        if ($this->lockout->bannedFor($ipKey) !== null || $this->lockout->bannedFor($mailKey) !== null) {
            return $this->form($client, $login->string('ru'), $sealedRequest, $email, self::TOO_MANY, 429);
        }
        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false || !$this->settings->isEmailAllowed($email)
            || $password === '' || strlen($password) > 1024
        ) {
            $this->recordFailure($ipKey, $mailKey, 'not_allowed');

            return $this->form($client, $login->string('ru'), $sealedRequest, $email, 'このメールアドレスではログインできません。', 400);
        }
        try {
            $valid = $this->verifier->verify($this->settings->account($email, $password));
        } catch (MailException) {
            return $this->form($client, $login->string('ru'), $sealedRequest, $email, self::UNREACHABLE, 503);
        }
        if (!$valid) {
            $this->recordFailure($ipKey, $mailKey, 'bad_credentials');

            return $this->form($client, $login->string('ru'), $sealedRequest, $email, 'メールアドレスまたはパスワードが正しくありません。', 400);
        }
        $this->lockout->reset($ipKey);
        $this->lockout->reset($mailKey);

        $grantId = TokenIssuer::newId();
        $code = $this->sealer->seal(Sealer::AUTHORIZATION_CODE, [
            'jti' => TokenIssuer::newId(),
            'gid' => $grantId,
            'sub' => $email,
            'pwd' => $password,
            'cid' => $client->id,
            'ru' => $login->string('ru'),
            'cc' => $login->string('cc'),
            'aud' => $this->settings->resource,
            'exp' => ($this->clock)() + OAuthSettings::CODE_TTL_SEC,
        ]);
        $this->audit->record('oauth_login', ['client' => $client->id, 'grant' => $grantId, 'account' => $email]);

        return $this->withCookie($this->redirect($login->string('ru'), ['code' => $code], $state), '', 0);
    }

    private function recordFailure(string $ipKey, string $mailKey, string $reason): void
    {
        $this->audit->record('oauth_login_failed', ['reason' => $reason]);
        foreach ([$ipKey, $mailKey] as $key) {
            if ($this->lockout->recordFailure($key)) {
                $this->audit->record('lockout_started', [
                    'scope' => strtok($key, ':'),
                    'ban_sec' => $this->lockout->banSec(),
                ]);
            }
        }
    }

    private function form(
        OAuthClient $client,
        string $redirectUri,
        string $sealedRequest,
        string $email = '',
        ?string $error = null,
        int $status = 200,
    ): Response {
        $parts = parse_url($redirectUri);
        $host = $parts['host'] ?? $redirectUri;
        $origin = ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return LoginPage::form($client->name, $host, $origin, $sealedRequest, $email, $error, $status);
    }

    /**
     * Redirect to the client with the response parameters, the state and iss (RFC 9207).
     *
     * @param array<string, string> $params
     */
    private function redirect(string $redirectUri, array $params, ?string $state): Response
    {
        if ($state !== null) {
            $params['state'] = $state;
        }
        $params['iss'] = $this->settings->issuer();
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return Response::redirect($redirectUri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * The CSRF cookie, limited to the MCP directory so other applications on the same site never receive it.
     */
    private function withCookie(Response $response, string $value, int $maxAge): Response
    {
        $path = parse_url($this->settings->resource, PHP_URL_PATH);
        $cookie = sprintf(
            '%s=%s; Max-Age=%d; Path=%s; Secure; HttpOnly; SameSite=Lax',
            self::CSRF_COOKIE,
            rawurlencode($value),
            $maxAge,
            is_string($path) && $path !== '' ? $path : '/',
        );

        return new Response($response->status, $response->headers + ['Set-Cookie' => $cookie], $response->body);
    }
}

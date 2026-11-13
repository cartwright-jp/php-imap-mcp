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
 * The token endpoint (OAuth 2.1 section 3.2; SPEC.md 7): authorization_code with PKCE S256, and
 * refresh_token with rotation. Called by the client's server, never by the user's browser.
 */
final class TokenEndpoint
{
    /** RFC 7636: 43-128 characters of unreserved characters. */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly OAuthSettings $settings,
        private readonly Sealer $sealer,
        private readonly IdStore $ids,
        private readonly TokenIssuer $issuer,
        private readonly CredentialVerifier $verifier,
        private readonly Lockout $lockout,
        private readonly AuditLogger $audit,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function handle(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::empty(405, ['Allow' => 'POST']);
        }
        $contentType = strtolower(trim(explode(';', $request->header('Content-Type') ?? '')[0]));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            return self::error(400, 'invalid_request', 'Content-Type must be application/x-www-form-urlencoded');
        }
        $single = ['grant_type', 'code', 'redirect_uri', 'code_verifier', 'refresh_token', 'resource', 'client_id'];
        foreach ($single as $name) {
            if ($request->isRepeated($name, true)) {
                return self::error(400, 'invalid_request', sprintf("'%s' must not be repeated", $name));
            }
        }

        $client = $this->authenticateClient($request);
        if ($client instanceof Response) {
            if ($this->lockout->recordFailure($request->remoteAddr)) {
                $this->audit->record('lockout_started', ['ban_sec' => $this->lockout->banSec()]);
            }

            return $client;
        }

        return match ($request->form('grant_type')) {
            'authorization_code' => $this->exchangeCode($request, $client),
            'refresh_token' => $this->refresh($request, $client),
            null => self::error(400, 'invalid_request', "'grant_type' is required"),
            default => self::error(
                400,
                'unsupported_grant_type',
                'Only authorization_code and refresh_token are supported',
            ),
        };
    }

    private function exchangeCode(Request $request, OAuthClient $client): Response
    {
        $code = Claims::of(
            $this->sealer->open(Sealer::AUTHORIZATION_CODE, (string) $request->form('code')),
            ['jti', 'gid', 'sub', 'pwd', 'cid', 'ru', 'cc', 'aud'],
        );
        if ($code === null || $code->int('exp') <= ($this->clock)()) {
            return $this->grantError('invalid or expired code');
        }
        // The code is bound to the client, the redirect URI and the PKCE challenge it was issued for.
        if (!hash_equals($code->string('cid'), $client->id)) {
            return $this->grantError('code issued to another client');
        }
        if ($request->form('redirect_uri') !== $code->string('ru')) {
            return $this->grantError('redirect_uri does not match');
        }
        $verifier = (string) $request->form('code_verifier');
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            return self::error(400, 'invalid_request', "'code_verifier' is missing or malformed");
        }
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals($code->string('cc'), $challenge)) {
            return $this->grantError('PKCE verification failed');
        }
        $resource = $request->form('resource');
        if ($resource !== null && !$this->settings->isOwnResource($resource)) {
            return self::error(400, 'invalid_target', 'The resource is not served by this authorization server');
        }
        // OAuth 2.1: an authorization code is used once. Marked last, so a failed attempt does not burn it.
        if (!$this->ids->markOnce(IdStore::USED_CODES, $code->string('jti'), $code->int('exp'))) {
            // A second use means the code leaked: the tokens issued for it are revoked (OAuth 2.1 4.1.3).
            $this->revokeGrant($code->string('gid'));
            $this->audit->record('oauth_code_reuse', ['client' => $client->id, 'grant' => $code->string('gid')]);

            return $this->grantError('code already used; the grant is revoked');
        }

        $this->audit->record('oauth_token', [
            'grant_type' => 'authorization_code',
            'client' => $client->id,
            'grant' => $code->string('gid'),
            'account' => $code->string('sub'),
        ]);

        return self::tokens($this->issuer->issue(
            $code->string('gid'),
            $code->string('sub'),
            $code->string('pwd'),
            $client->id,
        ));
    }

    private function refresh(Request $request, OAuthClient $client): Response
    {
        $token = Claims::of(
            $this->sealer->open(Sealer::REFRESH_TOKEN, (string) $request->form('refresh_token')),
            AccessTokenAuthenticator::STRING_CLAIMS,
        );
        if ($token === null || $token->int('exp') <= ($this->clock)()) {
            return $this->grantError('invalid or expired refresh token');
        }
        if (!hash_equals($token->string('cid'), $client->id)) {
            return $this->grantError('refresh token issued to another client');
        }
        $resource = $request->form('resource');
        if ($resource !== null && !$this->settings->isOwnResource($resource)) {
            return self::error(400, 'invalid_target', 'The resource is not served by this authorization server');
        }
        $scope = $request->form('scope');
        if ($scope !== null && $scope !== '' && $scope !== OAuthSettings::SCOPE) {
            return self::error(400, 'invalid_scope', sprintf("Only the scope '%s' exists", OAuthSettings::SCOPE));
        }
        $grantId = $token->string('gid');
        if ($this->ids->isMarked(IdStore::REVOKED_GRANTS, $grantId)) {
            return $this->grantError('grant revoked');
        }
        if ($this->ids->isMarked(IdStore::USED_REFRESH_TOKENS, $token->string('jti'))) {
            return $this->refreshReused($client, $grantId);
        }
        // The password may have changed on the mail server: then the grant ends here (SPEC.md 7). Checked
        // before the token is marked as used, so an unreachable mail server does not cost the user a login.
        $account = $this->settings->account($token->string('sub'), $token->string('pwd'));
        try {
            if (!$this->verifier->verify($account)) {
                $this->revokeGrant($grantId);

                return $this->grantError('the mail server rejected the stored credentials; sign in again');
            }
        } catch (MailException) {
            return self::error(503, 'temporarily_unavailable', 'The mail server is unreachable; try again later');
        }
        // Rotation (OAuth 2.1 4.3.1): each refresh token works once (atomic, so a race has one winner).
        if (!$this->ids->markOnce(IdStore::USED_REFRESH_TOKENS, $token->string('jti'), $token->int('exp'))) {
            return $this->refreshReused($client, $grantId);
        }

        $this->audit->record('oauth_token', [
            'grant_type' => 'refresh_token',
            'client' => $client->id,
            'grant' => $grantId,
            'account' => $token->string('sub'),
        ]);

        return self::tokens($this->issuer->issue($grantId, $token->string('sub'), $token->string('pwd'), $client->id));
    }

    /**
     * client_secret_basic, client_secret_post, or none for public clients (OAuth 2.1 2.4).
     */
    private function authenticateClient(Request $request): OAuthClient|Response
    {
        $basic = null;
        $authorization = $request->header('Authorization');
        if ($authorization !== null && preg_match('/^Basic +([A-Za-z0-9+\/=]+)\s*$/i', $authorization, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                return self::clientError(true);
            }
            [$id, $secret] = explode(':', $decoded, 2);
            $basic = [urldecode($id), urldecode($secret)];
        }
        $postId = $request->form('client_id');
        $postSecret = $request->form('client_secret');
        if ($basic !== null && $postSecret !== null) {
            return self::error(400, 'invalid_request', 'Use only one client authentication method');
        }
        [$id, $secret] = $basic ?? [$postId, $postSecret];
        if ($basic !== null && $postId !== null && $postId !== $id) {
            return self::error(400, 'invalid_request', 'client_id does not match the Authorization header');
        }
        $client = $id === null ? null : ($this->settings->clients[$id] ?? null);
        if ($client === null) {
            $this->audit->record('oauth_client_failed', ['reason' => 'unknown_client']);

            return self::clientError($basic !== null);
        }
        if ($client->secretSha256 !== null) {
            if ($secret === null || !hash_equals($client->secretSha256, hash('sha256', $secret))) {
                $this->audit->record('oauth_client_failed', ['reason' => 'bad_secret', 'client' => $client->id]);

                return self::clientError($basic !== null);
            }
        }

        return $client;
    }

    /**
     * A second use of a refresh token means it leaked: revoke the whole grant (RFC 9700 4.14.2).
     */
    private function refreshReused(OAuthClient $client, string $grantId): Response
    {
        $this->revokeGrant($grantId);
        $this->audit->record('oauth_refresh_reuse', ['client' => $client->id, 'grant' => $grantId]);

        return $this->grantError('refresh token already used; the grant is revoked');
    }

    private function revokeGrant(string $grantId): void
    {
        // Long enough to outlive every refresh token of the grant (they are extended on use).
        $this->ids->markOnce(IdStore::REVOKED_GRANTS, $grantId, ($this->clock)() + $this->settings->refreshTtlSec());
    }

    private function grantError(string $reason): Response
    {
        $this->audit->record('oauth_grant_failed', ['reason' => $reason]);

        return self::error(400, 'invalid_grant', 'The grant is invalid, expired, revoked or used');
    }

    private static function clientError(bool $basic): Response
    {
        return self::error(
            401,
            'invalid_client',
            'Client authentication failed',
            $basic ? ['WWW-Authenticate' => 'Basic realm="php-imap-mcp"'] : [],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function tokens(array $body): Response
    {
        return Response::json(200, $body, ['Pragma' => 'no-cache']);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function error(int $status, string $error, string $description, array $headers = []): Response
    {
        return Response::json(
            $status,
            ['error' => $error, 'error_description' => $description],
            ['Pragma' => 'no-cache'] + $headers,
        );
    }
}

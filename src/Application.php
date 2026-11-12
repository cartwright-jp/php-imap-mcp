<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\Guard;
use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Auth\OAuth\AccessTokenAuthenticator;
use Cartwright\ImapMcp\Auth\OAuth\AuthorizeEndpoint;
use Cartwright\ImapMcp\Auth\OAuth\CredentialVerifier;
use Cartwright\ImapMcp\Auth\OAuth\IdStore;
use Cartwright\ImapMcp\Auth\OAuth\Metadata;
use Cartwright\ImapMcp\Auth\OAuth\OAuthSettings;
use Cartwright\ImapMcp\Auth\OAuth\Sealer;
use Cartwright\ImapMcp\Auth\OAuth\TokenEndpoint;
use Cartwright\ImapMcp\Auth\OAuth\TokenIssuer;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigLoader;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Log\AuditLogger;
use Cartwright\ImapMcp\Log\JsonLinesAuditLogger;
use Cartwright\ImapMcp\Mail\Deadline;
use Cartwright\ImapMcp\Mail\Imap\ImapMailbox;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mcp\ErrorCode;
use Cartwright\ImapMcp\Mcp\Server;
use Cartwright\ImapMcp\Tools\GetEmailTool;
use Cartwright\ImapMcp\Tools\ListFoldersTool;
use Cartwright\ImapMcp\Tools\SaveDraftTool;
use Cartwright\ImapMcp\Tools\SearchEmailsTool;
use Cartwright\ImapMcp\Tools\Tool;
use Cartwright\ImapMcp\Tools\ToolRegistry;
use Closure;
use Throwable;

/**
 * Wires the server together and handles one HTTP request (SPEC.md 4 "1リクエストの処理の流れ").
 */
final class Application
{
    public const ROUTE_MCP = 'mcp';
    public const ROUTE_AUTHORIZE = 'authorize';
    public const ROUTE_TOKEN = 'token';
    public const ROUTE_RESOURCE_METADATA = 'resource-metadata';
    public const ROUTE_SERVER_METADATA = 'server-metadata';

    /** @var Closure(Account): Mailbox */
    private readonly Closure $mailboxFactory;
    private readonly IdStore $ids;

    /**
     * @param (Closure(Account): Mailbox)|null $mailboxFactory injectable for tests; defaults to IMAP
     */
    public function __construct(
        private readonly Config $config,
        private readonly AuditLogger $audit,
        private readonly Lockout $lockout,
        ?Closure $mailboxFactory = null,
        ?IdStore $ids = null,
    ) {
        $timeout = $config->runtime->imapTimeoutSec;
        $this->mailboxFactory = $mailboxFactory
            ?? static fn (Account $account): Mailbox => new ImapMailbox($account, $timeout);
        $this->ids = $ids ?? new IdStore($config->runtime->oauthDir());
    }

    public static function fromConfig(Config $config): self
    {
        $runtime = $config->runtime;

        return new self(
            $config,
            new JsonLinesAuditLogger($runtime->auditLogDir(), $runtime->timezone),
            new Lockout($runtime->lockoutDir(), $config->lockout),
        );
    }

    public function handle(Request $request): Response
    {
        $audit = $this->audit->withContext(['ip' => $request->remoteAddr]);
        $oauth = $this->config->oauth;
        $authenticator = new AccessTokenAuthenticator($oauth, new Sealer($oauth->key), $this->ids);
        $guard = new Guard($this->config, $authenticator, $this->lockout, $audit);
        $route = self::route($request->path);
        if ($route !== self::ROUTE_MCP) {
            return $this->handleOAuth($route, $request, $oauth, $guard, $audit);
        }

        $principal = $guard->check($request);
        if ($principal instanceof Response) {
            return $principal;
        }
        $account = $principal->account;
        $audit = $audit->withContext([
            'account' => $account->id,
            'oauth_client' => $principal->clientId,
            'grant' => $principal->grantId,
        ]);
        $deadline = Deadline::in($this->config->runtime->requestBudgetSec);
        // Connects only when a tool uses it; disconnects at the end of the request (SPEC.md 4).
        $mailbox = ($this->mailboxFactory)($account);
        try {
            $tools = new ToolRegistry($this->config->tools->filter($this->tools($account, $mailbox, $deadline)));

            return (new Server($tools, $audit))->handle($request);
        } finally {
            $mailbox->close();
        }
    }

    /**
     * Which part of the server a path belongs to. Works wherever index.php is installed:
     * /mcp/authorize, /authorize and the root's /.well-known/oauth-authorization-server/mcp all match.
     */
    public static function route(string $path): string
    {
        if (preg_match('#/\.well-known/oauth-protected-resource(/.*)?$#', $path) === 1) {
            return self::ROUTE_RESOURCE_METADATA;
        }
        if (preg_match('#/\.well-known/(oauth-authorization-server|openid-configuration)(/.*)?$#', $path) === 1) {
            return self::ROUTE_SERVER_METADATA;
        }
        $path = rtrim($path, '/');
        if (str_ends_with($path, '/authorize')) {
            return self::ROUTE_AUTHORIZE;
        }
        if (str_ends_with($path, '/token')) {
            return self::ROUTE_TOKEN;
        }

        return self::ROUTE_MCP;
    }

    private function handleOAuth(
        string $route,
        Request $request,
        OAuthSettings $oauth,
        Guard $guard,
        AuditLogger $audit,
    ): Response {
        $sealer = new Sealer($oauth->key);
        $verifier = new CredentialVerifier($this->mailboxFactory);
        // The login page is opened by the user's browser from anywhere: no IP restriction, its own defences.
        if ($route === self::ROUTE_AUTHORIZE) {
            return (new AuthorizeEndpoint($oauth, $sealer, $verifier, $this->lockout, $audit))->handle($request);
        }
        // Metadata and the token endpoint are used by the client's servers: IP restriction applies.
        $refused = $guard->checkSource($request);
        if ($refused !== null) {
            return $refused;
        }
        if ($route === self::ROUTE_TOKEN) {
            $issuer = new TokenIssuer($oauth, $sealer);

            return (new TokenEndpoint($oauth, $sealer, $this->ids, $issuer, $verifier, $this->lockout, $audit))
                ->handle($request);
        }
        if ($request->method !== 'GET') {
            return Response::empty(405, ['Allow' => 'GET']);
        }

        return Response::json(200, $route === self::ROUTE_RESOURCE_METADATA
            ? Metadata::protectedResource($oauth)
            : Metadata::authorizationServer($oauth));
    }

    /**
     * Entry point for public/index.php.
     *
     * @param string $configPath used unless the PHP_IMAP_MCP_CONFIG environment variable is set
     */
    public static function run(string $configPath): void
    {
        // PHP notices (also from libraries) must never reach the client: they would break the JSON and could
        // reveal server paths (SPEC.md 5). They still go to the server's error log.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        try {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
            $config = ConfigLoader::load(ConfigLoader::resolvePath($configPath), is_string($docRoot) ? $docRoot : null);
            foreach ($config->warnings as $warning) {
                error_log('php-imap-mcp: warning: ' . $warning);
            }
            $response = self::fromConfig($config)->handle(Request::fromGlobals(Server::MAX_BODY_BYTES));
        } catch (ConfigException $e) {
            // Refuse to run without a valid config (SPEC.md 8). Details go to the error log only.
            error_log('php-imap-mcp: ' . $e->getMessage());
            $response = self::internalError('Server configuration error');
        } catch (Throwable $e) {
            error_log(sprintf('php-imap-mcp: unhandled %s', $e::class));
            $response = self::internalError('Internal error');
        }
        $response->send();
    }

    /**
     * Every tool this version implements, before ToolPolicy removes the disabled ones (SPEC.md 6).
     *
     * @return list<Tool>
     */
    private function tools(Account $account, Mailbox $mailbox, Deadline $deadline): array
    {
        $policy = $this->config->policy;
        $timezone = $this->config->runtime->timezone;

        return [
            new ListFoldersTool($mailbox, $policy),
            new SearchEmailsTool($mailbox, $policy, $timezone, $deadline),
            new GetEmailTool($mailbox, $policy, $timezone),
            new SaveDraftTool($mailbox, $account, $policy, $timezone),
        ];
    }

    private static function internalError(string $message): Response
    {
        return Response::json(500, [
            'jsonrpc' => '2.0',
            'error' => ['code' => ErrorCode::INTERNAL_ERROR, 'message' => $message],
        ]);
    }
}

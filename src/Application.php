<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\ConfigAccountResolver;
use Cartwright\ImapMcp\Auth\Guard;
use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Auth\TokenAuthenticator;
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
    /** @var Closure(Account): Mailbox */
    private readonly Closure $mailboxFactory;

    /**
     * @param (Closure(Account): Mailbox)|null $mailboxFactory injectable for tests; defaults to IMAP
     */
    public function __construct(
        private readonly Config $config,
        private readonly AuditLogger $audit,
        private readonly Lockout $lockout,
        ?Closure $mailboxFactory = null,
    ) {
        $timeout = $config->runtime->imapTimeoutSec;
        $this->mailboxFactory = $mailboxFactory
            ?? static fn (Account $account): Mailbox => new ImapMailbox($account, $timeout);
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
        $deadline = Deadline::in($this->config->runtime->requestBudgetSec);
        $audit = $this->audit->withContext(['ip' => $request->remoteAddr]);
        $guard = new Guard($this->config, new TokenAuthenticator($this->config->tokens), $this->lockout, $audit);
        $token = $guard->check($request);
        if ($token instanceof Response) {
            return $token;
        }

        $account = (new ConfigAccountResolver($this->config->accounts))->resolve($token);
        // The account id, not the address, so the log holds no personal data beyond the IP.
        $audit = $audit->withContext(['account' => $account->id, 'token' => $token->label]);
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
